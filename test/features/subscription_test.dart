import 'package:flutter_test/flutter_test.dart';
import 'package:three_d_subscriber/features/subscriber/domain/subscriber.dart';

void main() {
  test(
    'subscription calculates expiry and remaining duration consistently',
    () {
      final now = DateTime.utc(2026, 8, 29, 10);
      final subscription = Subscription(
        id: 's1',
        packageName: '30 GB',
        totalBytes: 3000,
        usedBytes: 1200,
        startedAt: now.subtract(const Duration(days: 10)),
        expiresAt: now.add(const Duration(days: 6, hours: 3)),
      );

      expect(subscription.isExpiredAt(now), isFalse);
      expect(subscription.remainingAt(now), const Duration(days: 6, hours: 3));
      expect(subscription.remainingDaysAt(now), 7);
      expect(subscription.remainingBytes, 1800);
      expect(subscription.usagePercentage, 0.4);
      expect(
        subscription.isExpiredAt(now.add(const Duration(days: 7))),
        isTrue,
      );
    },
  );
}
