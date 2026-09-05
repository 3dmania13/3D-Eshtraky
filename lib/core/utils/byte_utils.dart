abstract final class ByteUtils {
  static const int bytesPerKilobyte = 1024;
  static const int bytesPerMegabyte = bytesPerKilobyte * 1024;
  static const int bytesPerGigabyte = bytesPerMegabyte * 1024;
  static const int bytesPerTerabyte = bytesPerGigabyte * 1024;

  static int gigabytesToBytes(num gigabytes) =>
      (gigabytes * bytesPerGigabyte).round();

  static double bytesToGigabytes(int bytes) => bytes / bytesPerGigabyte;

  static int remainingBytes({required int total, required int used}) =>
      (total - used).clamp(0, total);

  static double usagePercentage({required int total, required int used}) {
    if (total <= 0) return 0;
    return (used / total).clamp(0, 1);
  }

  static String format(int bytes, {int fractionDigits = 1}) {
    if (bytes >= bytesPerTerabyte) {
      return '${(bytes / bytesPerTerabyte).toStringAsFixed(fractionDigits)} TB';
    }
    if (bytes >= bytesPerGigabyte) {
      return '${bytesToGigabytes(bytes).toStringAsFixed(fractionDigits)} GB';
    }
    if (bytes >= bytesPerMegabyte) {
      return '${(bytes / bytesPerMegabyte).toStringAsFixed(fractionDigits)} MB';
    }
    if (bytes >= bytesPerKilobyte) {
      return '${(bytes / bytesPerKilobyte).toStringAsFixed(fractionDigits)} KB';
    }
    return '$bytes B';
  }
}
