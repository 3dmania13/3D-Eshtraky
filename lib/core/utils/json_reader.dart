import '../errors/app_exception.dart';

class JsonReader {
  const JsonReader(this.json);

  final Map<String, dynamic> json;

  String string(String key) {
    final value = json[key];
    if (value is String && value.isNotEmpty) return value;
    throw AppException(
      'الحقل $key مفقود أو غير صالح.',
      code: 'invalid_response',
    );
  }

  String? nullableString(String key) {
    final value = json[key];
    if (value == null) return null;
    if (value is String) return value;
    throw AppException('الحقل $key غير صالح.', code: 'invalid_response');
  }

  int integer(String key) {
    final value = json[key];
    if (value is int) return value;
    if (value is num) return value.toInt();
    throw AppException(
      'الحقل $key مفقود أو غير صالح.',
      code: 'invalid_response',
    );
  }

  double decimal(String key) {
    final value = json[key];
    if (value is num) return value.toDouble();
    throw AppException(
      'الحقل $key مفقود أو غير صالح.',
      code: 'invalid_response',
    );
  }

  bool boolean(String key) {
    final value = json[key];
    if (value is bool) return value;
    throw AppException(
      'الحقل $key مفقود أو غير صالح.',
      code: 'invalid_response',
    );
  }

  DateTime dateTime(String key) {
    final parsed = DateTime.tryParse(string(key));
    if (parsed != null) return parsed;
    throw AppException('التاريخ $key غير صالح.', code: 'invalid_response');
  }
}
