import '../domain/subscriber_notification.dart';

/// Platform-neutral boundary for a future Firebase Cloud Messaging adapter.
/// Phase 1 deliberately registers no Firebase implementation or configuration.
abstract interface class PushNotificationService {
  Future<void> initialize();
  Stream<SubscriberNotification> get foregroundNotifications;
  Future<String?> getDeviceToken();
}

class DisabledPushNotificationService implements PushNotificationService {
  const DisabledPushNotificationService();

  @override
  Stream<SubscriberNotification> get foregroundNotifications =>
      const Stream.empty();

  @override
  Future<String?> getDeviceToken() async => null;

  @override
  Future<void> initialize() async {}
}
