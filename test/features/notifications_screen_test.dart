import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:three_d_subscriber/core/providers/app_providers.dart';
import 'package:three_d_subscriber/features/notifications/data/notification_repository.dart';
import 'package:three_d_subscriber/features/notifications/domain/subscriber_notification.dart';
import 'package:three_d_subscriber/features/notifications/presentation/notifications_screen.dart';

class TestNotifications implements NotificationRepository {
  var item = SubscriberNotification(
    id: '1',
    type: NotificationType.broadcast,
    title: 'إشعار تجريبي',
    body: 'تفاصيل الإشعار كاملة',
    createdAt: DateTime(2026, 9, 26),
    isRead: false,
  );
  int writes = 0;
  bool fail = false;
  @override
  Future<List<SubscriberNotification>> getNotifications() async => [item];
  @override
  Future<void> markRead(String id) async {
    writes++;
    if (fail) throw Exception('offline');
    item = item.copyWith(isRead: true);
  }

  @override
  Future<void> markAllRead() async => item = item.copyWith(isRead: true);
}

Future<void> showScreen(
  WidgetTester tester,
  TestNotifications repository,
) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        notificationRepositoryProvider.overrideWithValue(repository),
        notificationsProvider.overrideWith(
          (ref) => ref.watch(notificationRepositoryProvider).getNotifications(),
        ),
      ],
      child: const MaterialApp(home: NotificationsScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(() => initializeDateFormatting('ar'));

  testWidgets(
    'opening and closing leaves unread; OK persists and read items reopen',
    (tester) async {
      final repository = TestNotifications();
      await showScreen(tester, repository);
      await tester.tap(find.text('إشعار تجريبي'));
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsOneWidget);
      expect(find.byType(SelectableText), findsOneWidget);
      expect(repository.writes, 0);
      await tester.tap(find.text('إغلاق'));
      await tester.pumpAndSettle();
      expect(repository.item.isRead, false);
      await tester.tap(find.text('إشعار تجريبي'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('موافق'));
      await tester.pumpAndSettle();
      expect(repository.item.isRead, true);
      expect(repository.writes, 1);
      expect(find.byType(AlertDialog), findsNothing);
      await tester.tap(find.text('إشعار تجريبي'));
      await tester.pumpAndSettle();
      expect(find.byType(AlertDialog), findsOneWidget);
      await tester.tap(find.text('موافق'));
      await tester.pumpAndSettle();
      expect(repository.writes, 1);
    },
  );

  testWidgets('failed acknowledgement stays unread and can be retried', (
    tester,
  ) async {
    final repository = TestNotifications()..fail = true;
    await showScreen(tester, repository);
    await tester.tap(find.text('إشعار تجريبي'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('موافق'));
    await tester.pumpAndSettle();
    expect(repository.item.isRead, false);
    expect(find.text('تعذر حفظ حالة الإشعار. حاول مرة أخرى.'), findsOneWidget);
    repository.fail = false;
    await tester.tap(find.text('موافق'));
    await tester.pumpAndSettle();
    expect(repository.item.isRead, true);
    expect(find.byType(AlertDialog), findsNothing);
  });
}
