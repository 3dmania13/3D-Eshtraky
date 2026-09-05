import '../../subscriber/domain/subscriber.dart';
import '../../usage/domain/usage_models.dart';

enum PredictionKind {
  dataWillFinish,
  subscriptionExpiresFirst,
  insufficientData,
}

class UsagePrediction {
  const UsagePrediction({required this.kind, this.estimatedDays});

  final PredictionKind kind;
  final int? estimatedDays;

  String get arabicMessage {
    return switch (kind) {
      PredictionKind.dataWillFinish =>
        'بمعدل استهلاكك الحالي، قد تنتهي البيانات بعد $estimatedDays أيام.',
      PredictionKind.subscriptionExpiresFirst =>
        'ستنتهي صلاحية الاشتراك قبل استهلاك كامل البيانات المتبقية.',
      PredictionKind.insufficientData =>
        'نحتاج إلى بيانات استخدام أكثر لتقديم توقع دقيق.',
    };
  }
}

class UsagePredictionService {
  const UsagePredictionService();

  UsagePrediction predict({
    required Subscription subscription,
    required List<DailyUsage> history,
    required DateTime now,
  }) {
    final completedDays = history
        .where(
          (item) => item.date.isBefore(DateTime(now.year, now.month, now.day)),
        )
        .toList();
    if (completedDays.isEmpty || subscription.remainingBytes <= 0) {
      return const UsagePrediction(kind: PredictionKind.insufficientData);
    }

    final total = completedDays.fold<int>(
      0,
      (sum, item) => sum + item.totalBytes,
    );
    final average = total / completedDays.length;
    if (average <= 0) {
      return const UsagePrediction(kind: PredictionKind.insufficientData);
    }

    final daysUntilDataEnds = (subscription.remainingBytes / average).ceil();
    final daysUntilExpiry = subscription.remainingAt(now).inHours / 24;
    if (daysUntilExpiry <= daysUntilDataEnds) {
      return const UsagePrediction(
        kind: PredictionKind.subscriptionExpiresFirst,
      );
    }
    return UsagePrediction(
      kind: PredictionKind.dataWillFinish,
      estimatedDays: daysUntilDataEnds,
    );
  }
}
