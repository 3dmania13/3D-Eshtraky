import '../../../core/utils/json_reader.dart';

enum NotificationType {
  expiringSoon,
  packageExpired,
  usage75,
  usage90,
  lowBalance,
  dataExhausted,
  rechargeSuccessful,
  newDevice,
  unusualDeviceCount,
  systemMessage,
  broadcast,
}

class SubscriberNotification {
  const SubscriberNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    this.linkTitle,
    this.linkUrl,
    required this.createdAt,
    required this.isRead,
  });

  final String id;
  final NotificationType type;
  final String title;
  final String body;
  final String? linkTitle;
  final String? linkUrl;
  final DateTime createdAt;
  final bool isRead;

  SubscriberNotification copyWith({bool? isRead}) => SubscriberNotification(
    id: id,
    type: type,
    title: title,
    body: body,
    linkTitle: linkTitle,
    linkUrl: linkUrl,
    createdAt: createdAt,
    isRead: isRead ?? this.isRead,
  );

  factory SubscriberNotification.fromJson(Map<String, dynamic> json) {
    final reader = JsonReader(json);
    return SubscriberNotification(
      id: reader.string('id'),
      type:
          NotificationType.values
              .where((item) => item.name == reader.string('type'))
              .firstOrNull ??
          NotificationType.systemMessage,
      title: reader.string('title'),
      body: reader.string('body'),
      linkTitle: reader.nullableString('link_title'),
      linkUrl: reader.nullableString('link_url'),
      createdAt: reader.dateTime('created_at'),
      isRead: reader.boolean('is_read'),
    );
  }
}
