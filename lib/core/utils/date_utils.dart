import 'package:intl/intl.dart';

abstract final class AppDateUtils {
  static final _date = DateFormat('d MMMM yyyy', 'ar');
  static final _dateShort = DateFormat('d MMM', 'ar');
  static final _dateTime = DateFormat('d MMM، h:mm a', 'ar');
  static final _time = DateFormat('h:mm a', 'ar');

  static String date(DateTime value) => _date.format(value.toLocal());
  static String shortDate(DateTime value) => _dateShort.format(value.toLocal());
  static String dateTime(DateTime value) => _dateTime.format(value.toLocal());
  static String time(DateTime value) => _time.format(value.toLocal());

  static String duration(Duration value) {
    final hours = value.inHours;
    final minutes = value.inMinutes.remainder(60);
    if (hours > 0) return '$hours س $minutes د';
    return '$minutes دقيقة';
  }

  static Duration remainingUntil(DateTime expiry, DateTime now) {
    final value = expiry.difference(now);
    return value.isNegative ? Duration.zero : value;
  }
}
