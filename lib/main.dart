import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'app.dart';
import 'features/notifications/data/push_notification_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('ar');
  try {
    await FirebasePushNotificationService.initializeFirebase();
    await FirebasePushNotificationService.instance.initialize();
  } catch (_) {
    // A transient Firebase failure must not prevent account access.
  }
  runApp(const ProviderScope(child: SubscriberApp()));
}
