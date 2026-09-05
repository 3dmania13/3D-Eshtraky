import 'package:flutter_test/flutter_test.dart';
import 'package:three_d_subscriber/core/utils/byte_utils.dart';

void main() {
  group('ByteUtils', () {
    test('converts gigabytes to bytes and back', () {
      final bytes = ByteUtils.gigabytesToBytes(1.5);

      expect(bytes, 1610612736);
      expect(ByteUtils.bytesToGigabytes(bytes), closeTo(1.5, 0.000001));
    });

    test('remaining quota never becomes negative', () {
      expect(ByteUtils.remainingBytes(total: 100, used: 35), 65);
      expect(ByteUtils.remainingBytes(total: 100, used: 140), 0);
    });

    test('usage percentage is clamped and handles zero quota', () {
      expect(ByteUtils.usagePercentage(total: 100, used: 40), 0.4);
      expect(ByteUtils.usagePercentage(total: 100, used: 120), 1);
      expect(ByteUtils.usagePercentage(total: 0, used: 10), 0);
    });

    test('formats large usage values as terabytes', () {
      expect(ByteUtils.format(ByteUtils.bytesPerTerabyte * 3), '3.0 TB');
    });
  });
}
