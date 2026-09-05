import '../../../core/utils/json_reader.dart';

enum NotificationType {
  expiringSoon,
  packageExpired,
  usage75,
  usage90,
  dataExhausted,
  rechargeSuccessful,
  newDevice,
  unusualDeviceCount,
  systemMessage,
}

class SubscriberNotification {
  const SubscriberNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.createdAt,
    required this.isRead,
  });

  final String id;
  final NotificationType type;
  final String title;
  final String body;
  final DateTime createdAt;
  final bool isRead;

  SubscriberNotification copyWith({bool? isRead}) => SubscriberNotification(
    id: id,
    type: type,
    title: title,
    body: body,
    createdAt: createdAt,
    isRead: isRead ?? this.isRead,
  );

  factory SubscriberNotification.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return SubscriberNotification(
      id: reader.string('id'),
      type: NotificationType.values.byName(reader.string('type')),
      title: reader.string('title'),
      body: reader.string('body'),
      createdAt: reader.dateTime('created_at'),
      isRead: reader.boolean('is_read'),
    );
  }
}
