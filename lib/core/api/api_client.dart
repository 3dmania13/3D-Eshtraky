import 'dart:async';

import 'package:dio/dio.dart';

import '../auth/token_storage.dart';
import '../config/app_config.dart';
import '../errors/app_exception.dart';
import 'api_endpoints.dart';

class ApiClient {
  ApiClient({required this.tokenStorage, this.onSessionExpired, Dio? dio})
    : _dio = dio ?? Dio(_options()),
      _refreshDio = Dio(_options()) {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          if (!options.path.startsWith('/api/v1/auth/')) {
            final token = await tokenStorage.readAccessToken();
            if (token != null && token.isNotEmpty) {
              options.headers['Authorization'] = 'Bearer $token';
            }
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          final request = error.requestOptions;
          final shouldRefresh =
              error.response?.statusCode == 401 &&
              request.extra['retried_after_refresh'] != true &&
              !request.path.startsWith('/api/v1/auth/');
          if (!shouldRefresh || !await _refreshTokens()) {
            handler.next(error);
            return;
          }
          try {
            final token = await tokenStorage.readAccessToken();
            request.extra['retried_after_refresh'] = true;
            request.headers['Authorization'] = 'Bearer $token';
            handler.resolve(await _dio.fetch<Object?>(request));
          } on DioException catch (retryError) {
            handler.next(retryError);
          }
        },
      ),
    );
  }

  static BaseOptions _options() => BaseOptions(
    baseUrl: AppConfig.apiBaseUrl,
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 20),
    headers: const {'Accept': 'application/json'},
  );

  final Dio _dio;
  final Dio _refreshDio;
  final TokenStorage tokenStorage;
  final FutureOr<void> Function()? onSessionExpired;
  Future<bool>? _refreshInFlight;

  Future<Map<String, dynamic>> getJson(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    try {
      final response = await _dio.get<Object?>(path, queryParameters: query);
      return _asObject(response.data);
    } on DioException catch (error) {
      throw AppException.fromDio(error);
    }
  }

  Future<List<Map<String, dynamic>>> getJsonList(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    try {
      final response = await _dio.get<Object?>(path, queryParameters: query);
      return _asList(response.data);
    } on DioException catch (error) {
      throw AppException.fromDio(error);
    }
  }

  Future<Map<String, dynamic>> postJson(String path, {Object? data}) async {
    try {
      final response = await _dio.post<Object?>(path, data: data);
      return _asObject(response.data);
    } on DioException catch (error) {
      throw AppException.fromDio(error);
    }
  }

  Future<Map<String, dynamic>> patchJson(String path, {Object? data}) async {
    try {
      final response = await _dio.patch<Object?>(path, data: data);
      return _asObject(response.data);
    } on DioException catch (error) {
      throw AppException.fromDio(error);
    }
  }

  Future<bool> _refreshTokens() {
    final current = _refreshInFlight;
    if (current != null) return current;
    final future = _performRefresh();
    _refreshInFlight = future;
    return future.whenComplete(() => _refreshInFlight = null);
  }

  Future<bool> _performRefresh() async {
    final refreshToken = await tokenStorage.readRefreshToken();
    if (refreshToken == null || refreshToken.isEmpty) {
      await _expireSession();
      return false;
    }
    try {
      final response = await _refreshDio.post<Object?>(
        ApiEndpoints.refresh,
        data: {'refreshToken': refreshToken},
      );
      final json = _asObject(response.data);
      final access = json['accessToken'];
      final refresh = json['refreshToken'];
      if (access is! String || refresh is! String) {
        await _expireSession();
        return false;
      }
      await tokenStorage.writeTokens(
        StoredTokens(accessToken: access, refreshToken: refresh),
      );
      return true;
    } on DioException {
      await _expireSession();
      return false;
    }
  }

  Future<void> _expireSession() async {
    await tokenStorage.clear();
    await onSessionExpired?.call();
  }

  Map<String, dynamic> _asObject(Object? value) {
    if (value is Map<String, dynamic>) return value;
    throw const AppException('استجابة الخادم غير صالحة.');
  }

  List<Map<String, dynamic>> _asList(Object? value) {
    if (value is List) {
      return value
          .map((item) {
            if (item is Map<String, dynamic>) return item;
            throw const AppException('استجابة الخادم غير صالحة.');
          })
          .toList(growable: false);
    }
    throw const AppException('استجابة الخادم غير صالحة.');
  }
}
