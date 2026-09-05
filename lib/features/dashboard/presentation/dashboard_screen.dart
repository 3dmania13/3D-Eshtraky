import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../../auth/application/auth_controller.dart';
import '../../subscriber/domain/subscriber.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(usageDataProvider);
    ref.invalidate(usagePredictionProvider);
    ref.invalidate(notificationsProvider);
    final _ = await ref.refresh(subscriberProvider.future);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final subscriber = ref.watch(subscriberProvider);
    final username = ref.watch(
      authControllerProvider.select((state) => state.subscriber?.username),
    );
    final unread = ref.watch(unreadNotificationCountProvider);
    return MainScaffold(
      title: username ?? AppStrings.appName,
      currentIndex: 0,
      actions: [
        Badge(
          isLabelVisible: unread > 0,
          label: Text('$unread'),
          child: IconButton(
            tooltip: AppStrings.notifications,
            onPressed: () => context.push('/notifications'),
            icon: const Icon(Icons.notifications_none_rounded),
          ),
        ),
        const SizedBox(width: 8),
      ],
      body: PageFrame(
        child: AsyncContent<Subscriber>(
          value: subscriber,
          onRetry: () => ref.invalidate(subscriberProvider),
          data: (data) => RefreshIndicator(
            onRefresh: () => _refresh(ref),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                _WelcomeHeader(subscriber: data),
                const SizedBox(height: 18),
                _SubscriptionCard(subscriber: data),
                const SizedBox(height: 24),
                const SectionTitle(AppStrings.quickAccess),
                const SizedBox(height: 12),
                const _QuickActions(),
                const SizedBox(height: 24),
                const SectionTitle(AppStrings.usagePrediction),
                const SizedBox(height: 12),
                const _PredictionCard(),
                const SizedBox(height: 24),
                const SectionTitle(AppStrings.secondaryServices),
                const SizedBox(height: 12),
                const _SecondaryServices(),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _WelcomeHeader extends StatelessWidget {
  const _WelcomeHeader({required this.subscriber});

  final Subscriber subscriber;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        CircleAvatar(
          radius: 24,
          backgroundColor: Theme.of(context).colorScheme.primaryContainer,
          child: Icon(
            Icons.person_rounded,
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('مرحبًا،', style: Theme.of(context).textTheme.bodyMedium),
              Text(
                subscriber.username,
                textDirection: TextDirection.ltr,
                style: Theme.of(
                  context,
                ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
              ),
            ],
          ),
        ),
        StatusBadge(
          label: subscriber.connectionStatus == ConnectionStatus.connected
              ? AppStrings.connected
              : AppStrings.disconnected,
          isPositive: subscriber.connectionStatus == ConnectionStatus.connected,
        ),
      ],
    );
  }
}

class _SubscriptionCard extends StatelessWidget {
  const _SubscriptionCard({required this.subscriber});

  final Subscriber subscriber;

  @override
  Widget build(BuildContext context) {
    final subscription = subscriber.subscription;
    final remaining = subscription.remainingAt(DateTime.now());
    final remainingDays = subscription.remainingDaysAt(DateTime.now());
    final remainingLabel = remainingDays > 0
        ? AppStrings.daysRemaining(remainingDays)
        : AppStrings.hoursRemaining(remaining.inHours);
    final scheme = Theme.of(context).colorScheme;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/subscription'),
        child: Container(
          constraints: const BoxConstraints(minHeight: 230),
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                scheme.primary,
                const Color(0xFF0842B8),
                const Color(0xFF082B79),
              ],
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
            ),
          ),
          child: Stack(
            children: [
              const PositionedDirectional(
                top: -65,
                end: -55,
                child: _HeroOrb(size: 180, color: Color(0x1FFFFFFF)),
              ),
              const PositionedDirectional(
                bottom: -75,
                start: 90,
                child: _HeroOrb(size: 150, color: Color(0x1018BCEB)),
              ),
              Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              AppStrings.remaining,
                              style: TextStyle(
                                color: Colors.white70,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 3),
                            Text(
                              AppStrings.gbOf(
                                ByteUtils.format(subscription.remainingBytes),
                                ByteUtils.format(
                                  subscription.totalBytes,
                                  fractionDigits: 0,
                                ),
                              ),
                              textDirection: TextDirection.ltr,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 25,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              subscription.packageName,
                              style: const TextStyle(color: Colors.white70),
                            ),
                          ],
                        ),
                      ),
                      SizedBox.square(
                        dimension: 74,
                        child: Stack(
                          alignment: Alignment.center,
                          children: [
                            TweenAnimationBuilder<double>(
                              tween: Tween(
                                begin: 0,
                                end: subscription.usagePercentage,
                              ),
                              duration: const Duration(milliseconds: 1100),
                              curve: Curves.easeOutCubic,
                              builder: (context, value, _) =>
                                  CircularProgressIndicator(
                                    value: value,
                                    strokeWidth: 8,
                                    strokeCap: StrokeCap.round,
                                    backgroundColor: Colors.white24,
                                    color: const Color(0xFF39E6C4),
                                  ),
                            ),
                            Text(
                              '${(subscription.usagePercentage * 100).round()}%',
                              textDirection: TextDirection.ltr,
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  const Divider(color: Colors.white24),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: _WhiteMetric(
                          icon: Icons.schedule_rounded,
                          label: AppStrings.remainingTime,
                          value: remainingLabel,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: _WhiteMetric(
                          icon: Icons.devices_rounded,
                          label: AppStrings.connectedNow,
                          value: AppStrings.deviceCount(
                            subscriber.activeDeviceCount,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HeroOrb extends StatelessWidget {
  const _HeroOrb({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
  );
}

class _WhiteMetric extends StatelessWidget {
  const _WhiteMetric({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Icon(icon, color: Colors.white70, size: 22),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: const TextStyle(color: Colors.white60, fontSize: 11),
              ),
              Text(
                value,
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _QuickActions extends StatelessWidget {
  const _QuickActions();

  @override
  Widget build(BuildContext context) {
    const items = [
      (
        AppStrings.myUsage,
        Icons.donut_large_rounded,
        '/usage',
        Color(0xFF075DE7),
      ),
      (
        AppStrings.myDevices,
        Icons.devices_other_rounded,
        '/devices',
        Color(0xFF7B4DFF),
      ),
      (
        AppStrings.recharges,
        Icons.receipt_long_rounded,
        '/recharges',
        Color(0xFF13A76B),
      ),
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 620 ? 3 : 2;
        return GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            crossAxisSpacing: 12,
            mainAxisSpacing: 12,
            childAspectRatio: constraints.maxWidth < 380 ? 1.25 : 1.45,
          ),
          itemCount: items.length,
          itemBuilder: (context, index) {
            final item = items[index];
            return Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(24),
                onTap: () {
                  if (item.$3 == '/usage' || item.$3 == '/devices') {
                    context.go(item.$3);
                  } else {
                    context.push(item.$3);
                  }
                },
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Container(
                        width: 43,
                        height: 43,
                        decoration: BoxDecoration(
                          color: item.$4.withValues(alpha: .11),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Icon(item.$2, color: item.$4),
                      ),
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              item.$1,
                              style: const TextStyle(
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                          Icon(
                            Icons.arrow_back_rounded,
                            size: 18,
                            color: item.$4,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }
}

class _PredictionCard extends ConsumerWidget {
  const _PredictionCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final prediction = ref.watch(usagePredictionProvider);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Row(
          children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFFE6FBF1), Color(0xFFEDF7FF)],
                  begin: Alignment.topRight,
                  end: Alignment.bottomLeft,
                ),
                borderRadius: BorderRadius.circular(15),
              ),
              child: const Icon(
                Icons.auto_graph_rounded,
                color: AppTheme.accent,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: prediction.when(
                loading: () => const LinearProgressIndicator(),
                error: (_, _) => const Text('تعذر حساب توقع الاستهلاك.'),
                data: (value) => Text(value.arabicMessage),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SecondaryServices extends StatelessWidget {
  const _SecondaryServices();

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(
        children: [
          _ServiceTile(
            icon: Icons.notifications_none_rounded,
            label: AppStrings.notifications,
            onTap: () => context.push('/notifications'),
          ),
          const Divider(height: 1),
          _ServiceTile(
            icon: Icons.swap_horiz_rounded,
            label: AppStrings.fileTransfer,
            badge: AppStrings.comingSoon,
            onTap: () => context.push('/file-transfer'),
          ),
          const Divider(height: 1),
          _ServiceTile(
            icon: Icons.support_agent_rounded,
            label: AppStrings.support,
            onTap: () => _showPlaceholder(context),
          ),
          const Divider(height: 1),
          _ServiceTile(
            icon: Icons.person_outline_rounded,
            label: AppStrings.account,
            onTap: () => context.go('/account'),
          ),
        ],
      ),
    );
  }

  void _showPlaceholder(BuildContext context) {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text(AppStrings.support),
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
}

class _ServiceTile extends StatelessWidget {
  const _ServiceTile({
    required this.icon,
    required this.label,
    required this.onTap,
    this.badge,
  });

  final IconData icon;
  final String label;
  final String? badge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap,
      leading: Icon(icon, color: Theme.of(context).colorScheme.primary),
      title: Text(label),
      trailing: badge == null
          ? const Icon(Icons.chevron_left_rounded)
          : Chip(label: Text(badge!), visualDensity: VisualDensity.compact),
    );
  }
}
