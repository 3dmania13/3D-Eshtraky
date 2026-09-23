import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/application/auth_controller.dart';
import '../../features/dashboard/application/usage_prediction_service.dart';
import '../../features/devices/data/device_repository.dart';
import '../../features/devices/domain/subscriber_device.dart';
import '../../features/notifications/data/notification_repository.dart';
import '../../features/notifications/data/push_notification_service.dart';
import '../../features/notifications/data/notification_preferences.dart';
import '../../features/notifications/application/notification_preferences_controller.dart';
import '../../features/notifications/domain/subscriber_notification.dart';
import '../../features/recharges/data/recharge_repository.dart';
import '../../features/recharges/domain/recharge_transaction.dart';
import '../../features/subscriber/data/subscriber_repository.dart';
import '../../features/subscriber/domain/subscriber.dart';
import '../../features/usage/data/usage_repository.dart';
import '../config/app_config.dart';

final subscriberRepositoryProvider = Provider<SubscriberRepository>(
  (ref) => AppConfig.useMockData
      ? const MockSubscriberRepository()
      : ApiSubscriberRepository(ref.watch(apiClientProvider)),
);
final usageRepositoryProvider = Provider<UsageRepository>(
  (ref) => AppConfig.useMockData
      ? const MockUsageRepository()
      : ApiUsageRepository(ref.watch(apiClientProvider)),
);
final deviceRepositoryProvider = Provider<DeviceRepository>(
  (ref) => AppConfig.useMockData
      ? MockDeviceRepository()
      : ApiDeviceRepository(ref.watch(apiClientProvider)),
);
final rechargeRepositoryProvider = Provider<RechargeRepository>(
  (ref) => AppConfig.useMockData
      ? const MockRechargeRepository()
      : ApiRechargeRepository(ref.watch(apiClientProvider)),
);
final notificationRepositoryProvider = Provider<NotificationRepository>(
  (ref) => AppConfig.useMockData
      ? MockNotificationRepository()
      : ApiNotificationRepository(ref.watch(apiClientProvider)),
);
final pushNotificationServiceProvider = Provider<PushNotificationService>(
  (ref) => FirebasePushNotificationService.instance,
);
final notificationPreferencesStoreProvider =
    Provider<NotificationPreferencesStore>((ref) => NotificationPreferencesStore());
final notificationPreferencesProvider = StateNotifierProvider<
    NotificationPreferencesController, NotificationPreferences>(
  (ref) => NotificationPreferencesController(
    ref.watch(notificationPreferencesStoreProvider),
  ),
);

final subscriberProvider = FutureProvider<Subscriber>((ref) {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  return ref.watch(subscriberRepositoryProvider).getCurrentSubscriber();
});

final usageDataProvider = FutureProvider<UsageData>((ref) async {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  final repository = ref.watch(usageRepositoryProvider);
  final summaryFuture = repository.getSummary();
  final dailyFuture = repository.getDaily();
  final sessionsFuture = repository.getSessions();
  return UsageData(
    summary: await summaryFuture,
    daily: await dailyFuture,
    sessions: await sessionsFuture,
  );
});

final usagePredictionProvider = FutureProvider<UsagePrediction>((ref) async {
  final subscriber = await ref.watch(subscriberProvider.future);
  final usage = await ref.watch(usageDataProvider.future);
  return const UsagePredictionService().predict(
    subscription: subscriber.subscription,
    history: usage.daily,
    now: DateTime.now(),
  );
});

final devicesProvider = FutureProvider<List<SubscriberDevice>>((ref) {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  return ref.watch(deviceRepositoryProvider).getDevices();
});

final rechargesProvider = FutureProvider<List<RechargeTransaction>>((ref) {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  return ref.watch(rechargeRepositoryProvider).getRecharges();
});

final notificationsProvider = FutureProvider<List<SubscriberNotification>>((
  ref,
) {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  return ref.watch(notificationRepositoryProvider).getNotifications();
});

final unreadNotificationCountProvider = Provider<int>((ref) {
  return ref
      .watch(notificationsProvider)
      .maybeWhen(
        data: (items) => items.where((item) => !item.isRead).length,
        orElse: () => 0,
      );
});
