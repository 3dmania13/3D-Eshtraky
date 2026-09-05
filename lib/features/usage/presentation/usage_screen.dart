import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../data/usage_repository.dart';
import '../domain/usage_models.dart';

class UsageScreen extends ConsumerWidget {
  const UsageScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(usageDataProvider);
    return MainScaffold(
      title: AppStrings.myUsage,
      currentIndex: 1,
      body: PageFrame(
        child: AsyncContent<UsageData>(
          value: value,
          onRetry: () => ref.invalidate(usageDataProvider),
          data: (usage) => RefreshIndicator(
            onRefresh: () => ref.refresh(usageDataProvider.future),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              children: [
                _UsageSummaryGrid(summary: usage.summary),
                const SizedBox(height: 24),
                const SectionTitle(AppStrings.dailyUsage),
                const SizedBox(height: 12),
                _UsageChart(records: usage.daily),
                const SizedBox(height: 24),
                const SectionTitle(AppStrings.usageHistory),
                const SizedBox(height: 12),
                ...usage.daily.map(
                  (item) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _DailyUsageTile(item: item),
                  ),
                ),
                const SizedBox(height: 14),
                const SectionTitle(AppStrings.sessions),
                const SizedBox(height: 12),
                ...usage.sessions.map(
                  (session) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: _SessionTile(session: session),
                  ),
                ),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _UsageSummaryGrid extends StatelessWidget {
  const _UsageSummaryGrid({required this.summary});

  final UsageSummary summary;

  @override
  Widget build(BuildContext context) {
    final items = [
      (AppStrings.today, summary.today, Icons.today_rounded),
      (AppStrings.yesterday, summary.yesterday, Icons.history_rounded),
      (AppStrings.thisWeek, summary.week, Icons.date_range_rounded),
      (AppStrings.thisMonth, summary.month, Icons.calendar_month_rounded),
    ];
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 620 ? 4 : 2;
        return GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: items.length,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: columns,
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
            childAspectRatio: 1.05,
          ),
          itemBuilder: (context, index) {
            final item = items[index];
            return _BreakdownCard(
              title: item.$1,
              usage: item.$2,
              icon: item.$3,
            );
          },
        );
      },
    );
  }
}

class _BreakdownCard extends StatelessWidget {
  const _BreakdownCard({
    required this.title,
    required this.usage,
    required this.icon,
  });

  final String title;
  final UsageBreakdown usage;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  icon,
                  size: 20,
                  color: Theme.of(context).colorScheme.primary,
                ),
                const SizedBox(width: 7),
                Expanded(
                  child: Text(
                    title,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ],
            ),
            const Spacer(),
            Text(
              ByteUtils.format(usage.totalBytes),
              textDirection: TextDirection.ltr,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
            ),
            const SizedBox(height: 8),
            Text(
              '${AppStrings.download}: ${ByteUtils.format(usage.downloadBytes)}',
              textDirection: TextDirection.ltr,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            Text(
              '${AppStrings.upload}: ${ByteUtils.format(usage.uploadBytes)}',
              textDirection: TextDirection.ltr,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _UsageChart extends StatelessWidget {
  const _UsageChart({required this.records});

  final List<DailyUsage> records;

  @override
  Widget build(BuildContext context) {
    final items = records.reversed.take(10).toList().reversed.toList();
    final maxGb = items.fold<double>(0, (value, item) {
      final gb = ByteUtils.bytesToGigabytes(item.totalBytes);
      return gb > value ? gb : value;
    });
    final chartMax = maxGb <= 0 ? 1.0 : maxGb * 1.12;
    final horizontalInterval = _horizontalInterval(chartMax);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 14),
        child: Column(
          children: [
            SizedBox(
              height: 190,
              child: BarChart(
                BarChartData(
                  maxY: chartMax,
                  alignment: BarChartAlignment.spaceAround,
                  gridData: FlGridData(
                    show: true,
                    drawVerticalLine: false,
                    horizontalInterval: horizontalInterval,
                    getDrawingHorizontalLine: (_) =>
                        const FlLine(color: Color(0xFFE9EFF2), strokeWidth: 1),
                  ),
                  borderData: FlBorderData(show: false),
                  titlesData: const FlTitlesData(
                    leftTitles: AxisTitles(
                      sideTitles: SideTitles(showTitles: false),
                    ),
                    rightTitles: AxisTitles(
                      sideTitles: SideTitles(showTitles: false),
                    ),
                    topTitles: AxisTitles(
                      sideTitles: SideTitles(showTitles: false),
                    ),
                    bottomTitles: AxisTitles(
                      sideTitles: SideTitles(showTitles: false),
                    ),
                  ),
                  barTouchData: BarTouchData(
                    enabled: true,
                    touchTooltipData: BarTouchTooltipData(
                      getTooltipColor: (_) => const Color(0xFF163A4A),
                      tooltipPadding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 8,
                      ),
                      fitInsideHorizontally: true,
                      fitInsideVertically: true,
                      getTooltipItem: (group, groupIndex, rod, rodIndex) {
                        final item = items[group.x];
                        return BarTooltipItem(
                          '${AppDateUtils.shortDate(item.date)}\n',
                          const TextStyle(color: Colors.white70, fontSize: 11),
                          children: [
                            TextSpan(
                              text: ByteUtils.format(item.totalBytes),
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ],
                          textAlign: TextAlign.center,
                        );
                      },
                    ),
                  ),
                  barGroups: [
                    for (var index = 0; index < items.length; index++)
                      BarChartGroupData(
                        x: index,
                        barRods: [
                          BarChartRodData(
                            toY: ByteUtils.bytesToGigabytes(
                              items[index].totalBytes,
                            ),
                            width: 14,
                            borderRadius: const BorderRadius.vertical(
                              top: Radius.circular(5),
                            ),
                            gradient: LinearGradient(
                              colors: [
                                Theme.of(context).colorScheme.primary,
                                Theme.of(context).colorScheme.secondary,
                              ],
                              begin: Alignment.bottomCenter,
                              end: Alignment.topCenter,
                            ),
                          ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 10),
            const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                _ChartLegendMark(),
                SizedBox(width: 7),
                Text(
                  'إجمالي الاستهلاك اليومي',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                ),
              ],
            ),
            if (items.isNotEmpty) ...[
              const SizedBox(height: 8),
              Directionality(
                textDirection: TextDirection.ltr,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      AppDateUtils.shortDate(items.first.date),
                      style: const TextStyle(
                        color: Color(0xFF5F6F77),
                        fontSize: 11,
                      ),
                    ),
                    Text(
                      AppDateUtils.shortDate(items.last.date),
                      style: const TextStyle(
                        color: Color(0xFF5F6F77),
                        fontSize: 11,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  double _horizontalInterval(double maxY) {
    if (maxY <= 2) return 0.5;
    if (maxY <= 10) return 2;
    if (maxY <= 50) return 10;
    if (maxY <= 200) return 50;
    if (maxY <= 1000) return 200;
    return 500;
  }
}

class _ChartLegendMark extends StatelessWidget {
  const _ChartLegendMark();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 11,
      height: 11,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(3),
        gradient: LinearGradient(
          colors: [
            Theme.of(context).colorScheme.primary,
            Theme.of(context).colorScheme.secondary,
          ],
          begin: Alignment.bottomCenter,
          end: Alignment.topCenter,
        ),
      ),
    );
  }
}

class _DailyUsageTile extends StatelessWidget {
  const _DailyUsageTile({required this.item});

  final DailyUsage item;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: Theme.of(context).colorScheme.primaryContainer,
          child: const Icon(Icons.bar_chart_rounded),
        ),
        title: Text(AppDateUtils.date(item.date)),
        subtitle: Text(
          '${AppStrings.download} ${ByteUtils.format(item.downloadBytes)}  •  '
          '${AppStrings.upload} ${ByteUtils.format(item.uploadBytes)}',
          textDirection: TextDirection.ltr,
        ),
        trailing: Text(
          ByteUtils.format(item.totalBytes),
          textDirection: TextDirection.ltr,
          style: const TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
    );
  }
}

class _SessionTile extends StatelessWidget {
  const _SessionTile({required this.session});

  final RadiusSession session;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: ExpansionTile(
        leading: Icon(
          session.isActive ? Icons.wifi_rounded : Icons.wifi_off_rounded,
          color: session.isActive ? Colors.green : Colors.grey,
        ),
        title: Text(AppDateUtils.dateTime(session.startTime)),
        subtitle: Text(
          '${AppDateUtils.duration(session.duration)} • ${ByteUtils.format(session.totalBytes)}',
          textDirection: TextDirection.ltr,
        ),
        childrenPadding: const EdgeInsets.fromLTRB(18, 0, 18, 16),
        children: [
          _SessionDetail(label: 'Session ID', value: session.sessionId),
          _SessionDetail(label: 'IP', value: session.framedIp),
          _SessionDetail(label: 'MAC', value: session.callingStationId),
          _SessionDetail(label: 'NAS', value: session.networkIdentifier),
        ],
      ),
    );
  }
}

class _SessionDetail extends StatelessWidget {
  const _SessionDetail({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 7),
      child: Row(
        children: [
          Text('$label:'),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.end,
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
