import 'package:flutter_test/flutter_test.dart';
import 'package:three_d_subscriber/features/dashboard/application/usage_prediction_service.dart';
import 'package:three_d_subscriber/features/subscriber/domain/subscriber.dart';
import 'package:three_d_subscriber/features/usage/domain/usage_models.dart';

void main() {
  const service = UsagePredictionService();
  final now = DateTime.utc(2026, 8, 29, 12);

  List<DailyUsage> history(int totalPerDay) => List.generate(
    4,
    (index) => DailyUsage(
      date: DateTime.utc(2026, 8, 28 - index),
      downloadBytes: totalPerDay - 100,
      uploadBytes: 100,
    ),
  );

  Subscription subscription({
    required int remaining,
    required int expiryDays,
  }) => Subscription(
    id: 's1',
    packageName: 'test',
    totalBytes: 10000,
    usedBytes: 10000 - remaining,
    startedAt: now.subtract(const Duration(days: 2)),
    expiresAt: now.add(Duration(days: expiryDays)),
  );

  test('predicts when data will finish', () {
    final result = service.predict(
      subscription: subscription(remaining: 3000, expiryDays: 10),
      history: history(1000),
      now: now,
    );

    expect(result.kind, PredictionKind.dataWillFinish);
    expect(result.estimatedDays, 3);
  });

  test('reports when subscription expires before data', () {
    final result = service.predict(
      subscription: subscription(remaining: 5000, expiryDays: 2),
      history: history(500),
      now: now,
    );

    expect(result.kind, PredictionKind.subscriptionExpiresFirst);
  });
}
