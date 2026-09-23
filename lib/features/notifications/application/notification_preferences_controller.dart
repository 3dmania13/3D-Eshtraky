import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/notification_preferences.dart';
import '../data/push_notification_service.dart';

enum NotificationPreference { subscriptionAlerts, deviceAlerts, systemMessages }

class NotificationPreferencesController
    extends StateNotifier<NotificationPreferences> {
  NotificationPreferencesController(this._store) : super(const NotificationPreferences()) {
    initialize();
  }

  final NotificationPreferencesStore _store;

  Future<void> initialize() async {
    try {
      final saved = await _store.read();
      final hasPermission = await FirebasePushNotificationService.instance
          .notificationsPermissionGranted();
      state = hasPermission ? saved : NotificationPreferences.allDisabled;
      if (!hasPermission) await _store.write(state);
    } catch (_) {
      // If Android has not initialized its notification service yet, do not
      // advertise external alerts as enabled.
      state = NotificationPreferences.allDisabled;
      await _store.write(state);
    }
  }

  Future<void> update(NotificationPreference preference, bool enabled) async {
    try {
      final hasPermission = await FirebasePushNotificationService.instance
          .notificationsPermissionGranted();
      if (!hasPermission) {
        state = NotificationPreferences.allDisabled;
      } else {
        state = switch (preference) {
          NotificationPreference.subscriptionAlerts =>
            state.copyWith(subscriptionAlerts: enabled),
          NotificationPreference.deviceAlerts => state.copyWith(deviceAlerts: enabled),
          NotificationPreference.systemMessages =>
            state.copyWith(systemMessages: enabled),
        };
      }
    } catch (_) {
      state = NotificationPreferences.allDisabled;
    }
    await _store.write(state);
  }
}
