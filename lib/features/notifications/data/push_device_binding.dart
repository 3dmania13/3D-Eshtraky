import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../core/api/api_endpoints.dart';
import '../../../core/auth/token_storage.dart';
import '../../../core/config/app_config.dart';
import 'notification_preferences.dart';
import 'push_notification_service.dart';

/// Serializes registration and revocation so a slow registration cannot bind a
/// device back to an account after logout or an account switch.
class PushDeviceBinding with WidgetsBindingObserver {
  PushDeviceBinding(this._tokens, this._preferences) {
    if (Platform.isAndroid) WidgetsBinding.instance.addObserver(this);
  }
  final TokenStorage _tokens;
  final NotificationPreferencesStore _preferences;
  final _storage = const FlutterSecureStorage();
  final _dio = Dio(
    BaseOptions(
      baseUrl: AppConfig.apiBaseUrl,
      connectTimeout: const Duration(seconds: 4),
      receiveTimeout: const Duration(seconds: 4),
    ),
  );
  Future<void> _queue = Future.value();
  StreamSubscription<String>? _refresh;
  Timer? _retry;
  String? _username;
  String? _lastToken;
  DateTime? _lastSync;
  int _generation = 0;

  void start(String username) {
    if (!Platform.isAndroid || AppConfig.useMockData) return;
    _username = username;
    _generation++;
    _lastToken = null;
    try {
      _refresh ??= FirebaseMessaging.instance.onTokenRefresh.listen(
        (_) => _schedule(),
      );
    } catch (_) {
      /* Push initialization must not invalidate a valid login. */
    }
    _retry ??= Timer.periodic(const Duration(minutes: 1), (_) => _schedule());
    _schedule();
  }

  Future<String> _installation() async {
    const key = 'subscriber_push_installation';
    final saved = await _storage.read(key: key);
    if (saved != null) return saved;
    final random = Random.secure();
    final id = List.generate(
      16,
      (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
    ).join();
    await _storage.write(key: key, value: id);
    return id;
  }

  void _schedule() {
    if (_username == null) return;
    final generation = _generation;
    _queue = _queue.then((_) async {
      try {
        if (_username == null || generation != _generation) return;
        if (!await FirebasePushNotificationService.instance
            .notificationsPermissionGranted()) {
          await _preferences.write(NotificationPreferences.allDisabled);
        }
        final preferences = await _preferences.read();
        final token = await FirebaseMessaging.instance.getToken();
        final access = await _tokens.readAccessToken();
        if (token == null || access == null || generation != _generation) {
          return;
        }
        if (_lastToken == token &&
            _lastSync != null &&
            DateTime.now().difference(_lastSync!) < const Duration(hours: 12)) {
          return;
        }
        final installation = await _installation();
        if (generation != _generation) return;
        await _dio.post<Object?>(
          ApiEndpoints.pushToken,
          data: {
            'token': token,
            'platform': 'android',
            'installation_id': installation,
            ...preferences.toPushJson(),
          },
          options: Options(headers: {'Authorization': 'Bearer $access'}),
        );
        if (generation == _generation) {
          _lastToken = token;
          _lastSync = DateTime.now();
        }
      } catch (_) {
        // Offline registration retries on resume and on the next timer tick.
      }
    });
  }

  Future<void> syncPreferences() async {
    _lastSync = null;
    _schedule();
    await _queue;
  }

  Future<void> stop() async {
    if (!Platform.isAndroid || AppConfig.useMockData) return;
    _username = null;
    _generation++;
    _lastToken = null;
    _retry?.cancel();
    _retry = null;
    final access = await _tokens.readAccessToken();
    _queue = _queue.then((_) async {
      try {
        final installation = await _installation();
        if (access != null) {
          await _dio.post<Object?>(
            '${ApiEndpoints.pushToken}/revoke',
            data: {'installation_id': installation},
            options: Options(headers: {'Authorization': 'Bearer $access'}),
          );
        }
      } catch (_) {
        /* Firebase token deletion also invalidates offline bindings. */
      }
      try {
        await FirebaseMessaging.instance.deleteToken().timeout(
          const Duration(seconds: 5),
        );
      } catch (_) {}
    });
    await _queue;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _schedule();
  }

  void dispose() {
    _generation++;
    _username = null;
    _retry?.cancel();
    unawaited(_refresh?.cancel());
    WidgetsBinding.instance.removeObserver(this);
    _dio.close();
  }
}
