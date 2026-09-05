import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../domain/subscriber.dart';

class SubscriptionScreen extends ConsumerWidget {
  const SubscriptionScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(subscriberProvider);
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.subscriptionDetails)),
      body: SafeArea(
        child: PageFrame(
          child: AsyncContent<Subscriber>(
            value: value,
            onRetry: () => ref.invalidate(subscriberProvider),
            data: (subscriber) {
              final subscription = subscriber.subscription;
              final remaining = subscription.remainingAt(DateTime.now());
              return RefreshIndicator(
                onRefresh: () => ref.refresh(subscriberProvider.future),
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: [
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(22),
                        child: Column(
                          children: [
                            SizedBox.square(
                              dimension: 130,
                              child: Stack(
                                alignment: Alignment.center,
                                children: [
                                  CircularProgressIndicator(
                                    value: subscription.usagePercentage,
                                    strokeWidth: 12,
                                    backgroundColor: const Color(0xFFE6EEF1),
                                  ),
                                  Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Text(
                                        '${(subscription.usagePercentage * 100).round()}%',
                                        textDirection: TextDirection.ltr,
                                        style: Theme.of(context)
                                            .textTheme
                                            .headlineSmall
                                            ?.copyWith(
                                              fontWeight: FontWeight.w900,
                                            ),
                                      ),
                                      const Text(AppStrings.used),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 20),
                            Text(
                              subscription.packageName,
                              style: Theme.of(context).textTheme.titleLarge
                                  ?.copyWith(fontWeight: FontWeight.w900),
                            ),
                            const SizedBox(height: 8),
                            StatusBadge(
                              label:
                                  subscriber.status == SubscriberStatus.active
                                  ? AppStrings.active
                                  : AppStrings.expired,
                              isPositive:
                                  subscriber.status == SubscriberStatus.active,
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 14),
                    Card(
                      child: Column(
                        children: [
                          _DetailRow(
                            label: AppStrings.total,
                            value: ByteUtils.format(subscription.totalBytes),
                          ),
                          const Divider(height: 1),
                          _DetailRow(
                            label: AppStrings.used,
                            value: ByteUtils.format(subscription.usedBytes),
                          ),
                          const Divider(height: 1),
                          _DetailRow(
                            label: AppStrings.remaining,
                            value: ByteUtils.format(
                              subscription.remainingBytes,
                            ),
                          ),
                          const Divider(height: 1),
                          _DetailRow(
                            label: AppStrings.expiresOn,
                            value: AppDateUtils.date(subscription.expiresAt),
                          ),
                          const Divider(height: 1),
                          _DetailRow(
                            label: AppStrings.remainingTime,
                            value:
                                subscription.remainingDaysAt(DateTime.now()) > 0
                                ? AppStrings.daysRemaining(
                                    subscription.remainingDaysAt(
                                      DateTime.now(),
                                    ),
                                  )
                                : AppStrings.hoursRemaining(remaining.inHours),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }
}
