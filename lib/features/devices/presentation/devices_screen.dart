import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../domain/subscriber_device.dart';

class DevicesScreen extends ConsumerWidget {
  const DevicesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(devicesProvider);
    return MainScaffold(
      title: AppStrings.myDevices,
      currentIndex: 2,
      body: PageFrame(
        child: AsyncContent<List<SubscriberDevice>>(
          value: value,
          onRetry: () => ref.invalidate(devicesProvider),
          data: (devices) {
            final active = devices.where((item) => item.isOnline).toList();
            final previous = devices.where((item) => !item.isOnline).toList();
            return RefreshIndicator(
              onRefresh: () => ref.refresh(devicesProvider.future),
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  _DeviceCountCard(count: active.length),
                  const SizedBox(height: 24),
                  const SectionTitle(AppStrings.activeDevices),
                  const SizedBox(height: 12),
                  if (active.isEmpty)
                    const EmptyState(
                      icon: Icons.devices_other_rounded,
                      message: AppStrings.noDevices,
                    )
                  else
                    ...active.map(
                      (device) => Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: _DeviceCard(
                          device: device,
                          onRename: () => _rename(context, ref, device),
                        ),
                      ),
                    ),
                  const SizedBox(height: 14),
                  const SectionTitle(AppStrings.previousDevices),
                  const SizedBox(height: 12),
                  ...previous.map(
                    (device) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: _DeviceCard(
                        device: device,
                        onRename: () => _rename(context, ref, device),
                      ),
                    ),
                  ),
                  const SizedBox(height: 24),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _rename(
    BuildContext context,
    WidgetRef ref,
    SubscriberDevice device,
  ) async {
    final controller = TextEditingController(text: device.friendlyName);
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text(AppStrings.rename),
        content: TextField(
          controller: controller,
          autofocus: true,
          maxLength: 30,
          decoration: const InputDecoration(labelText: AppStrings.deviceName),
          onSubmitted: (value) => Navigator.pop(context, value),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text(AppStrings.cancel),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text),
            child: const Text(AppStrings.save),
          ),
        ],
      ),
    );
    controller.dispose();
    if (name == null || name.trim().isEmpty) return;
    await ref.read(deviceRepositoryProvider).renameDevice(device.id, name);
    ref.invalidate(devicesProvider);
  }
}

class _DeviceCountCard extends StatelessWidget {
  const _DeviceCountCard({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Row(
          children: [
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                color: Theme.of(context).colorScheme.primaryContainer,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Icon(
                Icons.router_rounded,
                color: Theme.of(context).colorScheme.primary,
              ),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(AppStrings.connectedNow),
                  Text(
                    AppStrings.deviceCount(count),
                    style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
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

class _DeviceCard extends StatelessWidget {
  const _DeviceCard({required this.device, required this.onRename});

  final SubscriberDevice device;
  final VoidCallback onRename;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
        child: Column(
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: device.isOnline
                      ? const Color(0xFFE2F6EA)
                      : const Color(0xFFEEF1F3),
                  child: Icon(
                    _deviceIcon(device.friendlyName),
                    color: device.isOnline
                        ? Colors.green.shade700
                        : Colors.grey,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        device.friendlyName,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      Text(
                        device.isOnline
                            ? AppStrings.online
                            : AppStrings.offline,
                        style: TextStyle(
                          color: device.isOnline
                              ? Colors.green.shade700
                              : Colors.grey,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  tooltip: AppStrings.rename,
                  onPressed: onRename,
                  icon: const Icon(Icons.edit_outlined),
                ),
              ],
            ),
            const Divider(height: 24),
            _InfoRow(label: 'MAC', value: device.macAddress),
            _InfoRow(label: 'IP', value: device.ipAddress),
            if (device.isOnline) ...[
              _InfoRow(
                label: AppStrings.connectionStarted,
                value: AppDateUtils.dateTime(device.connectionStartedAt),
              ),
              _InfoRow(
                label: AppStrings.sessionUsage,
                value: ByteUtils.format(device.currentSessionBytes),
              ),
              _InfoRow(
                label: 'مدة الجلسة',
                value: AppDateUtils.duration(device.sessionDuration),
              ),
            ] else
              _InfoRow(
                label: AppStrings.lastSeen,
                value: AppDateUtils.dateTime(device.lastSeenAt),
              ),
          ],
        ),
      ),
    );
  }

  IconData _deviceIcon(String name) {
    if (name.contains('تلفزيون')) return Icons.tv_rounded;
    if (name.contains('لابتوب')) return Icons.laptop_rounded;
    return Icons.smartphone_rounded;
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
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
              textDirection: TextDirection.ltr,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
