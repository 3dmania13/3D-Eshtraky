import 'package:shared_preferences/shared_preferences.dart';

import '../domain/subscriber_notification.dart';

class NotificationPreferences {
  const NotificationPreferences({
    this.subscriptionAlerts = true,
    this.deviceAlerts = true,
    this.systemMessages = true,
  });

  static const allDisabled = NotificationPreferences(
    subscriptionAlerts: false,
    deviceAlerts: false,
    systemMessages: false,
  );

  final bool subscriptionAlerts;
  final bool deviceAlerts;
  final bool systemMessages;

  NotificationPreferences copyWith({
    bool? subscriptionAlerts,
    bool? deviceAlerts,
    bool? systemMessages,
  }) => NotificationPreferences(
    subscriptionAlerts: subscriptionAlerts ?? this.subscriptionAlerts,
    deviceAlerts: deviceAlerts ?? this.deviceAlerts,
    systemMessages: systemMessages ?? this.systemMessages,
  );

  bool allows(NotificationType type) => switch (type) {
    NotificationType.systemMessage || NotificationType.broadcast =>
      systemMessages,
    NotificationType.newDevice || NotificationType.unusualDeviceCount =>
      deviceAlerts,
    _ => subscriptionAlerts,
  };

  Map<String, bool> toPushJson() => {
    'subscription_alerts': subscriptionAlerts,
    'device_alerts': deviceAlerts,
    'system_messages': systemMessages,
  };
}

class NotificationPreferencesStore {
  static const _subscriptionKey = 'notification_subscription_alerts';
  static const _deviceKey = 'notification_device_alerts';
  static const _systemKey = 'notification_system_messages';

  Future<NotificationPreferences> read() async {
    final preferences = await SharedPreferences.getInstance();
    return NotificationPreferences(
      subscriptionAlerts: preferences.getBool(_subscriptionKey) ?? true,
      deviceAlerts: preferences.getBool(_deviceKey) ?? true,
      systemMessages: preferences.getBool(_systemKey) ?? true,
    );
  }

  Future<void> write(NotificationPreferences value) async {
    final preferences = await SharedPreferences.getInstance();
    await Future.wait([
      preferences.setBool(_subscriptionKey, value.subscriptionAlerts),
      preferences.setBool(_deviceKey, value.deviceAlerts),
      preferences.setBool(_systemKey, value.systemMessages),
    ]);
  }
}
