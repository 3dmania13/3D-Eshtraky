import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/mock/mock_data.dart';
import '../domain/subscriber_notification.dart';

abstract interface class NotificationRepository {
  Future<List<SubscriberNotification>> getNotifications();
  Future<void> markRead(String id);
}

class ApiNotificationRepository implements NotificationRepository {
  const ApiNotificationRepository(this._client);

  final ApiClient _client;

  @override
  Future<List<SubscriberNotification>> getNotifications() async {
    final rows = await _client.getJsonList(ApiEndpoints.notifications);
    return rows.map(SubscriberNotification.fromJson).toList(growable: false);
  }

  @override
  Future<void> markRead(String id) async {
    await _client.patchJson(ApiEndpoints.notificationRead(id));
  }
}

class MockNotificationRepository implements NotificationRepository {
  List<SubscriberNotification>? _items;

  List<SubscriberNotification> get _notifications =>
      _items ??= MockData.notifications(DateTime.now());

  @override
  Future<List<SubscriberNotification>> getNotifications() async {
    await Future<void>.delayed(const Duration(milliseconds: 400));
    return List.unmodifiable(_notifications);
  }

  @override
  Future<void> markRead(String id) async {
    await Future<void>.delayed(const Duration(milliseconds: 120));
    _items = _notifications
        .map((item) => item.id == id ? item.copyWith(isRead: true) : item)
        .toList();
  }
}
