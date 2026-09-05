import 'package:dio/dio.dart';

class AppException implements Exception {
  const AppException(this.message, {this.code});

  final String message;
  final String? code;

  factory AppException.fromDio(DioException error) {
    final responseData = error.response?.data;
    if (responseData is Map<String, dynamic>) {
      final errorData = responseData['error'];
      if (errorData is Map<String, dynamic>) {
        final message = errorData['message'];
        final code = errorData['code'];
        if (message is String && message.isNotEmpty) {
          return AppException(message, code: code is String ? code : null);
        }
      }
    }
    final status = error.response?.statusCode;
    if (status == 401) {
      return const AppException(
        'انتهت الجلسة، سجّل الدخول مجدداً.',
        code: 'unauthorized',
      );
    }
    if (error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.receiveTimeout) {
      return const AppException('انتهت مهلة الاتصال بالخادم.', code: 'timeout');
    }
    if (error.type == DioExceptionType.connectionError) {
      return const AppException(
        'تعذر الاتصال بالخادم. تحقق من الشبكة.',
        code: 'network',
      );
    }
    return const AppException('حدث خطأ غير متوقع من الخادم.', code: 'server');
  }

  @override
  String toString() => message;
}
