import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../../../firebase_options.dart';
import 'notification_preferences.dart';
import '../domain/subscriber_notification.dart';

/// Platform-neutral boundary for receiving Firebase Cloud Messaging alerts.
abstract interface class PushNotificationService {
  Future<void> initialize();
  Stream<SubscriberNotification> get foregroundNotifications;
  Future<String?> getDeviceToken();
}

/// Handles FCM on Android. Background notification messages are shown by the
/// operating system; foreground messages are presented through a local channel.
class FirebasePushNotificationService implements PushNotificationService {
  FirebasePushNotificationService._();

  static final instance = FirebasePushNotificationService._();
  static const _channel = AndroidNotificationChannel(
    'subscriber_alerts',
    'تنبيهات الاشتراك',
    description: 'تنبيهات الرصيد والأجهزة والاشتراك.',
    importance: Importance.high,
  );

  final _localNotifications = FlutterLocalNotificationsPlugin();
  final _foregroundController =
      StreamController<SubscriberNotification>.broadcast();
  final _openedController = StreamController<void>.broadcast();
  Stream<void> get openedNotifications => _openedController.stream;
  Future<bool> openedFromNotification() async =>
      Platform.isAndroid &&
      (await FirebaseMessaging.instance.getInitialMessage() != null ||
          (await _localNotifications.getNotificationAppLaunchDetails())
                  ?.didNotificationLaunchApp ==
              true);

  Future<bool> notificationsPermissionGranted() async {
    if (!Platform.isAndroid) return false;
    final settings = await FirebaseMessaging.instance.getNotificationSettings();
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  bool _initialized = false;

  static Future<void> initializeFirebase() async {
    if (Platform.isAndroid && Firebase.apps.isEmpty) {
      await Firebase.initializeApp(options: DefaultFirebaseOptions.android);
    }
  }

  @override
  Stream<SubscriberNotification> get foregroundNotifications =>
      _foregroundController.stream;

  @override
  Future<void> initialize() async {
    if (_initialized) return;
    if (!Platform.isAndroid) return;

    await _localNotifications.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      ),
      onDidReceiveNotificationResponse: (_) => _openedController.add(null),
    );
    final android = _localNotifications
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >();
    await android?.createNotificationChannel(_channel);
    await FirebaseMessaging.instance.requestPermission();
    FirebaseMessaging.onMessageOpenedApp.listen(
      (_) => _openedController.add(null),
    );

    FirebaseMessaging.onMessage.listen((message) async {
      final notification = _fromMessage(message);
      _foregroundController.add(notification);
      if (!(await NotificationPreferencesStore().read()).allows(
        notification.type,
      )) {
        return;
      }
      await _localNotifications.show(
        id:
            int.tryParse(notification.id)?.remainder(1 << 31) ??
            notification.id.hashCode.abs(),
        title: notification.title,
        body: notification.body,
        notificationDetails: const NotificationDetails(
          android: AndroidNotificationDetails(
            'subscriber_alerts',
            'تنبيهات الاشتراك',
            channelDescription: 'تنبيهات الرصيد والأجهزة والاشتراك.',
            icon: 'ic_stat_notification',
            importance: Importance.high,
            priority: Priority.high,
          ),
        ),
      );
    });
    _initialized = true;
  }

  @override
  Future<String?> getDeviceToken() => Platform.isAndroid
      ? FirebaseMessaging.instance.getToken()
      : Future.value(null);

  SubscriberNotification _fromMessage(RemoteMessage message) {
    final data = message.data;
    final types = NotificationType.values.where(
      (value) => value.name == data['type'],
    );
    return SubscriberNotification(
      id:
          data['notification_id'] ??
          message.messageId ??
          DateTime.now().microsecondsSinceEpoch.toString(),
      type: types.isEmpty ? NotificationType.systemMessage : types.first,
      title: message.notification?.title ?? data['title'] ?? 'إشعار جديد',
      body: message.notification?.body ?? data['body'] ?? '',
      linkTitle: data['link_title'],
      linkUrl: data['link_url'],
      createdAt: message.sentTime ?? DateTime.now(),
      isRead: false,
    );
  }
}
