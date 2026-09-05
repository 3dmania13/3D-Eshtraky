import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../domain/recharge_transaction.dart';

class RechargesScreen extends ConsumerWidget {
  const RechargesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(rechargesProvider);
    return Scaffold(
      appBar: AppBar(title: const Text(AppStrings.recharges)),
      body: SafeArea(
        child: PageFrame(
          child: AsyncContent<List<RechargeTransaction>>(
            value: value,
            onRetry: () => ref.invalidate(rechargesProvider),
            data: (transactions) => transactions.isEmpty
                ? const EmptyState(
                    icon: Icons.receipt_long_outlined,
                    message: AppStrings.noData,
                  )
                : RefreshIndicator(
                    onRefresh: () => ref.refresh(rechargesProvider.future),
                    child: ListView.separated(
                      physics: const AlwaysScrollableScrollPhysics(),
                      itemCount: transactions.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 12),
                      itemBuilder: (context, index) =>
                          _RechargeCard(transaction: transactions[index]),
                    ),
                  ),
          ),
        ),
      ),
    );
  }
}

class _RechargeCard extends StatelessWidget {
  const _RechargeCard({required this.transaction});

  final RechargeTransaction transaction;

  @override
  Widget build(BuildContext context) {
    final isSuccess = transaction.status == RechargeStatus.successful;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: isSuccess
                      ? const Color(0xFFE2F6EA)
                      : Theme.of(context).colorScheme.errorContainer,
                  child: Icon(
                    isSuccess ? Icons.check_rounded : Icons.schedule_rounded,
                    color: isSuccess
                        ? Colors.green.shade700
                        : Colors.orange.shade800,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        transaction.packageName,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      Text(AppDateUtils.dateTime(transaction.createdAt)),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      transaction.amount == null
                          ? '—'
                          : '${transaction.amount!.toStringAsFixed(0)} ر.ي',
                      textDirection: TextDirection.ltr,
                      style: const TextStyle(fontWeight: FontWeight.w900),
                    ),
                    Text(
                      isSuccess ? AppStrings.successful : AppStrings.pending,
                      style: TextStyle(
                        color: isSuccess
                            ? Colors.green.shade700
                            : Colors.orange.shade800,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const Divider(height: 28),
            _TransactionRow(
              label: AppStrings.addedData,
              value: ByteUtils.format(
                transaction.addedBytes,
                fractionDigits: 0,
              ),
            ),
            _TransactionRow(
              label: AppStrings.validity,
              value: transaction.validityDays == null
                  ? '—'
                  : AppStrings.validFor(transaction.validityDays!),
            ),
            _TransactionRow(
              label: AppStrings.expiresOn,
              value: transaction.generatedExpiry == null
                  ? '—'
                  : AppDateUtils.date(transaction.generatedExpiry!),
            ),
            _TransactionRow(
              label: AppStrings.transactionId,
              value: transaction.referenceId,
              ltr: true,
            ),
          ],
        ),
      ),
    );
  }
}

class _TransactionRow extends StatelessWidget {
  const _TransactionRow({
    required this.label,
    required this.value,
    this.ltr = false,
  });

  final String label;
  final String value;
  final bool ltr;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          Text(
            label,
            style: TextStyle(color: Theme.of(context).colorScheme.outline),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.end,
              textDirection: ltr ? TextDirection.ltr : null,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
