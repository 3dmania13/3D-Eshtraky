import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config.dart';
import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../../auth/application/auth_controller.dart';
import '../../subscriber/domain/subscriber.dart';

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final subscriber = ref.watch(subscriberProvider);
    return MainScaffold(
      title: AppStrings.account,
      currentIndex: 3,
      body: PageFrame(
        child: AsyncContent<Subscriber>(
          value: subscriber,
          onRetry: () => ref.invalidate(subscriberProvider),
          data: (data) => ListView(
            children: [
              _ProfileCard(subscriber: data),
              const SizedBox(height: 20),
              const SectionTitle(AppStrings.notificationPreferences),
              const SizedBox(height: 10),
              Card(
                child: Column(
                  children: [
                    _PreferenceSwitch(
                      title: AppStrings.subscriptionAlerts,
                      provider: subscriptionAlertsProvider,
                    ),
                    const Divider(height: 1),
                    _PreferenceSwitch(
                      title: AppStrings.deviceAlerts,
                      provider: deviceAlertsProvider,
                    ),
                    const Divider(height: 1),
                    _PreferenceSwitch(
                      title: AppStrings.systemMessages,
                      provider: systemMessagesProvider,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              Card(
                child: Column(
                  children: [
                    ListTile(
                      leading: const Icon(Icons.support_agent_rounded),
                      title: const Text(AppStrings.support),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => _placeholder(context, AppStrings.support),
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.swap_horiz_rounded),
                      title: const Text(AppStrings.fileTransfer),
                      subtitle: const Text(AppStrings.comingSoon),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => context.push('/file-transfer'),
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.live_tv_rounded),
                      title: const Text(AppStrings.iptv),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => _placeholder(context, AppStrings.iptv),
                    ),
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.privacy_tip_outlined),
                      title: const Text(AppStrings.privacy),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => _privacy(context),
                    ),
                    const Divider(height: 1),
                    const ListTile(
                      leading: Icon(Icons.info_outline_rounded),
                      title: Text(AppStrings.appVersion),
                      trailing: Text(AppConfig.appVersion),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: () => _logout(context, ref),
                icon: const Icon(Icons.logout_rounded),
                label: const Text(AppStrings.logout),
                style: OutlinedButton.styleFrom(
                  foregroundColor: Theme.of(context).colorScheme.error,
                  minimumSize: const Size.fromHeight(52),
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _logout(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text(AppStrings.logout),
        content: const Text(AppStrings.logoutConfirm),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text(AppStrings.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text(AppStrings.logout),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  void _placeholder(BuildContext context, String title) {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: const Text(AppStrings.placeholder),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('حسنًا'),
          ),
        ],
      ),
    );
  }

  void _privacy(BuildContext context) {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text(AppStrings.privacy),
        content: const Text(AppStrings.privacyDescription),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('حسنًا'),
          ),
        ],
      ),
    );
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.subscriber});

  final Subscriber subscriber;

  @override
  Widget build(BuildContext context) {
    final active = subscriber.status == SubscriberStatus.active;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Row(
          children: [
            CircleAvatar(
              radius: 32,
              backgroundColor: Theme.of(context).colorScheme.primaryContainer,
              child: Icon(
                Icons.person_rounded,
                size: 34,
                color: Theme.of(context).colorScheme.primary,
              ),
            ),
            const SizedBox(width: 15),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    subscriber.username,
                    textDirection: TextDirection.ltr,
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 6),
                  StatusBadge(
                    label: active ? AppStrings.active : AppStrings.expired,
                    isPositive: active,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PreferenceSwitch extends ConsumerWidget {
  const _PreferenceSwitch({required this.title, required this.provider});

  final String title;
  final StateProvider<bool> provider;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(provider);
    return SwitchListTile(
      value: value,
      onChanged: (next) => ref.read(provider.notifier).state = next,
      title: Text(title),
    );
  }
}
