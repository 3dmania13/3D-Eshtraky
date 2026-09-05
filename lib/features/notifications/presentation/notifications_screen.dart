import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../domain/subscriber_notification.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(notificationsProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text(AppStrings.notifications),
        actions: [
          TextButton(
            onPressed: value.valueOrNull?.any((item) => !item.isRead) == true
                ? () => _markAllRead(ref, value.valueOrNull!)
                : null,
            child: const Text(AppStrings.markAllRead),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: SafeArea(
        child: PageFrame(
          child: AsyncContent<List<SubscriberNotification>>(
            value: value,
            onRetry: () => ref.invalidate(notificationsProvider),
            data: (notifications) => notifications.isEmpty
                ? const EmptyState(
                    icon: Icons.notifications_none_rounded,
                    message: AppStrings.noNotifications,
                  )
                : RefreshIndicator(
                    onRefresh: () => ref.refresh(notificationsProvider.future),
                    child: ListView.separated(
                      physics: const AlwaysScrollableScrollPhysics(),
                      itemCount: notifications.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 10),
                      itemBuilder: (context, index) {
                        final notification = notifications[index];
                        return _NotificationCard(
                          notification: notification,
                          onTap: notification.isRead
                              ? null
                              : () => _markRead(ref, notification.id),
                        );
                      },
                    ),
                  ),
          ),
        ),
      ),
    );
  }

  Future<void> _markRead(WidgetRef ref, String id) async {
    await ref.read(notificationRepositoryProvider).markRead(id);
    ref.invalidate(notificationsProvider);
  }

  Future<void> _markAllRead(
    WidgetRef ref,
    List<SubscriberNotification> notifications,
  ) async {
    final repository = ref.read(notificationRepositoryProvider);
    await Future.wait(
      notifications
          .where((item) => !item.isRead)
          .map((item) => repository.markRead(item.id)),
    );
    ref.invalidate(notificationsProvider);
  }
}

class _NotificationCard extends StatelessWidget {
  const _NotificationCard({required this.notification, required this.onTap});

  final SubscriberNotification notification;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final color = _color(notification.type);
    return Card(
      color: notification.isRead
          ? Theme.of(context).colorScheme.surface
          : Color.alphaBlend(
              color.withValues(alpha: 0.08),
              Theme.of(context).colorScheme.surface,
            ),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                backgroundColor: color.withValues(alpha: 0.12),
                child: Icon(_icon(notification.type), color: color),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            notification.title,
                            style: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                        ),
                        if (!notification.isRead)
                          Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(
                              color: color,
                              shape: BoxShape.circle,
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 5),
                    Text(notification.body),
                    const SizedBox(height: 8),
                    Text(
                      AppDateUtils.dateTime(notification.createdAt),
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: Theme.of(context).colorScheme.outline,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  IconData _icon(NotificationType type) => switch (type) {
    NotificationType.expiringSoon ||
    NotificationType.packageExpired => Icons.schedule_rounded,
    NotificationType.usage75 ||
    NotificationType.usage90 ||
    NotificationType.dataExhausted => Icons.data_usage_rounded,
    NotificationType.rechargeSuccessful => Icons.check_circle_rounded,
    NotificationType.newDevice ||
    NotificationType.unusualDeviceCount => Icons.devices_rounded,
    NotificationType.systemMessage => Icons.campaign_rounded,
  };

  Color _color(NotificationType type) => switch (type) {
    NotificationType.rechargeSuccessful => const Color(0xFF18864B),
    NotificationType.newDevice ||
    NotificationType.systemMessage => const Color(0xFF176B87),
    NotificationType.expiringSoon ||
    NotificationType.usage75 ||
    NotificationType.usage90 => const Color(0xFFB54708),
    _ => const Color(0xFFB42318),
  };
}
