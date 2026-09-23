import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/utils/byte_utils.dart';
import '../../../core/utils/date_utils.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../domain/subscriber_device.dart';

/// Keeps the selected device speed visible immediately while the fresh device
/// list is loading from the server. The server remains the persisted source
/// after the next app launch.
final _deviceSpeedDisplayOverridesProvider =
    StateProvider.autoDispose<Map<String, String?>>((ref) => const {});

class DevicesScreen extends ConsumerWidget {
  const DevicesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(devicesProvider);
    final speedOverrides = ref.watch(_deviceSpeedDisplayOverridesProvider);
    return MainScaffold(
      title: AppStrings.myDevices,
      currentIndex: 2,
      body: PageFrame(
        child: AsyncContent<List<SubscriberDevice>>(
          value: value,
          onRetry: () => ref.invalidate(devicesProvider),
          data: (devices) {
            final displayedDevices = devices
                .map((device) {
                  if (!speedOverrides.containsKey(device.id)) return device;
                  final selection = speedOverrides[device.id];
                  return selection == null
                      ? device.copyWith(clearSpeedSelection: true)
                      : device.copyWith(speedSelection: selection);
                })
                .toList(growable: false);
            final active = displayedDevices
                .where((item) => item.isOnline)
                .toList();
            final previous = displayedDevices
                .where((item) => !item.isOnline)
                .toList();
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
                          onSetSpeed: () => _setSpeed(context, ref, device),
                          onDisconnect: () => _disconnect(context, ref, device),
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
                        onSetSpeed: () => _setSpeed(context, ref, device),
                        onDisconnect: null,
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

  Future<void> _disconnect(
    BuildContext context,
    WidgetRef ref,
    SubscriberDevice device,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text(AppStrings.disconnectDevice),
        content: Text(
          '${AppStrings.disconnectDeviceConfirm}\n\n${device.friendlyName}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text(AppStrings.cancel),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(dialogContext).colorScheme.error,
              foregroundColor: Theme.of(dialogContext).colorScheme.onError,
            ),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text(AppStrings.disconnect),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;
    try {
      await ref.read(deviceRepositoryProvider).disconnectDevice(device.id);
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text(AppStrings.deviceDisconnected)),
      );
      await Future<void>.delayed(const Duration(seconds: 1));
      ref.invalidate(devicesProvider);
    } catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error.toString())));
    }
  }

  Future<void> _setSpeed(
    BuildContext context,
    WidgetRef ref,
    SubscriberDevice device,
  ) async {
    const choices = <String, String>{
      'default': 'سرعة الباقة',
      '512K': '512 كيلوبت',
      '1M': '1 ميجابت',
      '2M': '2 ميجابت',
      '3M': '3 ميجابت',
      '4M': '4 ميجابت',
      '5M': '5 ميجابت',
    };
    final selected = await showDialog<String>(
      context: context,
      builder: (dialogContext) => SimpleDialog(
        title: Text('سرعة ${device.friendlyName}'),
        children: choices.entries
            .map(
              (choice) => SimpleDialogOption(
                onPressed: () => Navigator.pop(dialogContext, choice.key),
                child: Row(
                  children: [
                    Icon(
                      (device.speedSelection ?? 'default') == choice.key
                          ? Icons.radio_button_checked_rounded
                          : Icons.radio_button_off_rounded,
                      color: Theme.of(dialogContext).colorScheme.primary,
                    ),
                    const SizedBox(width: 12),
                    Text(choice.value),
                  ],
                ),
              ),
            )
            .toList(growable: false),
      ),
    );
    if (selected == null || !context.mounted) return;
    try {
      final result = await ref
          .read(deviceRepositoryProvider)
          .setDeviceSpeed(device.id, selected);
      if (!context.mounted) return;
      final selection = result.selection == 'default' ? null : result.selection;
      ref
          .read(_deviceSpeedDisplayOverridesProvider.notifier)
          .update(
            (current) => <String, String?>{...current, device.id: selection},
          );
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            result.appliedImmediately
                ? 'تم تطبيق سرعة الجهاز الآن.'
                : 'تم حفظ السرعة وستطبق عند اتصال الجهاز.',
          ),
        ),
      );
      ref.invalidate(devicesProvider);
    } catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error.toString())));
    }
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
  const _DeviceCard({
    required this.device,
    required this.onRename,
    required this.onSetSpeed,
    required this.onDisconnect,
  });

  final SubscriberDevice device;
  final VoidCallback onRename;
  final VoidCallback onSetSpeed;
  final VoidCallback? onDisconnect;

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
                        '${_deviceKind(device.friendlyName)} • ${device.isOnline ? AppStrings.online : AppStrings.offline}',
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
                IconButton(
                  tooltip: 'تحديد السرعة',
                  onPressed: onSetSpeed,
                  icon: const Icon(Icons.speed_rounded),
                ),
                if (onDisconnect != null)
                  IconButton(
                    tooltip: AppStrings.disconnectDevice,
                    color: Theme.of(context).colorScheme.error,
                    onPressed: onDisconnect,
                    icon: const Icon(Icons.delete_outline_rounded),
                  ),
              ],
            ),
            const Divider(height: 24),
            _InfoRow(label: 'MAC', value: device.macAddress),
            _InfoRow(label: 'IP', value: device.ipAddress),
            _InfoRow(
              label: 'السرعة',
              value: _speedLabel(device.speedSelection),
            ),
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
    final normalized = name.toLowerCase();
    if (normalized.contains('تلفزيون') || normalized.contains('tv')) {
      return Icons.tv_rounded;
    }
    if (normalized.contains('لابتوب') ||
        normalized.contains('لابتوب') ||
        normalized.contains('laptop') ||
        normalized.contains('notebook') ||
        normalized.startsWith('lt-')) {
      return Icons.laptop_rounded;
    }
    if (_isPhone(normalized)) return Icons.smartphone_rounded;
    return Icons.smartphone_rounded;
  }

  String _deviceKind(String name) {
    final normalized = name.toLowerCase();
    if (normalized.contains('تلفزيون') || normalized.contains('tv')) {
      return 'تلفزيون';
    }
    if (normalized.contains('لابتوب') ||
        normalized.contains('لابتوب') ||
        normalized.contains('laptop') ||
        normalized.contains('notebook') ||
        normalized.startsWith('lt-')) {
      return 'لابتوب';
    }
    return _isPhone(normalized) ? 'هاتف' : 'جهاز';
  }

  bool _isPhone(String name) =>
      name.contains('redmi') ||
      name.contains('xiaomi') ||
      name.startsWith('mi-') ||
      name.startsWith('mi ') ||
      name.contains('iphone') ||
      name.contains('samsung') ||
      name.contains('galaxy') ||
      name.contains('huawei') ||
      name.contains('honor') ||
      name.contains('oppo') ||
      name.contains('vivo') ||
      name.contains('infinix') ||
      name.contains('itel');

  String _speedLabel(String? selection) => switch (selection) {
    '512K' => '512 كيلوبت',
    '1M' => '1 ميجابت',
    '2M' => '2 ميجابت',
    '3M' => '3 ميجابت',
    '4M' => '4 ميجابت',
    '5M' => '5 ميجابت',
    _ => 'سرعة الباقة',
  };
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
