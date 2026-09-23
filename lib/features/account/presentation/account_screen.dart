import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/providers/app_providers.dart';
import '../../../core/strings/app_strings.dart';
import '../../../core/widgets/async_content.dart';
import '../../../core/widgets/main_scaffold.dart';
import '../../auth/application/auth_controller.dart';
import '../../notifications/application/notification_preferences_controller.dart';
import '../../subscriber/domain/subscriber.dart';

final connectionLimitProvider = FutureProvider<int?>((ref) async {
  ref.watch(
    authControllerProvider.select((state) => state.subscriber?.username),
  );
  final data = await ref
      .watch(apiClientProvider)
      .getJson(ApiEndpoints.connectionLimit);
  final limit = data['limit'];
  return limit is num ? limit.toInt() : null;
});

class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final subscriber = ref.watch(subscriberProvider);
    final connectionLimit = ref.watch(connectionLimitProvider);
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
              Card(
                child: ListTile(
                  leading: const Icon(Icons.speed_rounded),
                  title: const Text('سرعة الاشتراك'),
                  subtitle: const Text(
                    'اختر سرعة أقل أو اتركها مفتوحة حسب باقتك',
                  ),
                  trailing: const Icon(Icons.chevron_left_rounded),
                  onTap: () => _selectSpeed(context, ref),
                ),
              ),
              const SizedBox(height: 20),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.groups_rounded),
                  title: const Text('عدد المستخدمين في الاشتراك'),
                  subtitle: Text(
                    connectionLimit.when(
                      data: (limit) => limit == null
                          ? 'حدد الحد الأعلى للاتصالات المتزامنة'
                          : 'يسمح حاليًا بـ $limit اتصالات متزامنة',
                      loading: () => 'جارٍ تحميل الحد الحالي...',
                      error: (_, _) => 'حدد الحد الأعلى للاتصالات المتزامنة',
                    ),
                  ),
                  trailing: const Icon(Icons.chevron_left_rounded),
                  onTap: () => _selectConnectionLimit(context, ref),
                ),
              ),
              const SizedBox(height: 20),
              const SectionTitle(AppStrings.notificationPreferences),
              const SizedBox(height: 10),
              Card(
                child: Column(
                  children: [
                    _PreferenceSwitch(
                      title: AppStrings.subscriptionAlerts,
                      preference: NotificationPreference.subscriptionAlerts,
                    ),
                    const Divider(height: 1),
                    _PreferenceSwitch(
                      title: AppStrings.deviceAlerts,
                      preference: NotificationPreference.deviceAlerts,
                    ),
                    const Divider(height: 1),
                    _PreferenceSwitch(
                      title: AppStrings.systemMessages,
                      preference: NotificationPreference.systemMessages,
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
                      title: const Text('إرسال مشكلة أو اقتراح'),
                      subtitle: const Text('نستقبل رسالتك مباشرة من التطبيق'),
                      trailing: const Icon(Icons.chevron_left_rounded),
                      onTap: () => _sendFeedback(context, ref),
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

  Future<void> _selectSpeed(BuildContext context, WidgetRef ref) async {
    final client = ref.read(apiClientProvider);
    try {
      final data = await client.getJson(ApiEndpoints.speed);
      if (!context.mounted) return;
      final choices = (data['options'] as List<dynamic>).cast<String>();
      final selected = await showDialog<String>(
        context: context,
        builder: (dialogContext) => SimpleDialog(
          title: const Text('اختر سرعة الاشتراك'),
          children: choices
              .map(
                (value) => SimpleDialogOption(
                  onPressed: () => Navigator.pop(dialogContext, value),
                  child: Text(
                    value == 'open' ? 'مفتوح — حسب حد الباقة' : value,
                  ),
                ),
              )
              .toList(),
        ),
      );
      if (selected == null) return;
      final result = await client.putJson(
        ApiEndpoints.speed,
        data: {'selection': selected},
      );
      if (!context.mounted) return;
      final message = result['applied_immediately'] == true
          ? 'تم تغيير السرعة وتطبيقها على اتصالك الحالي.'
          : result['status'] == 'offline'
          ? 'تم حفظ السرعة. لا يوجد اتصال نشط حاليًا.'
          : 'تم حفظ السرعة، لكن تعذر تطبيقها فورًا على جميع الاتصالات. حاول مجددًا.';
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تعذر تحديث السرعة الآن.')));
    }
  }

  Future<void> _selectConnectionLimit(
    BuildContext context,
    WidgetRef ref,
  ) async {
    final client = ref.read(apiClientProvider);
    try {
      final data = await client.getJson(ApiEndpoints.connectionLimit);
      if (!context.mounted) return;
      final current = data['limit'] is num
          ? (data['limit'] as num).toInt()
          : null;
      final options = (data['options'] as List<dynamic>)
          .whereType<num>()
          .map((value) => value.toInt())
          .toList(growable: false);
      final selected = await showDialog<int>(
        context: context,
        builder: (dialogContext) => SimpleDialog(
          title: const Text('عدد الاتصالات المسموح بها'),
          children: options
              .map(
                (limit) => SimpleDialogOption(
                  onPressed: () => Navigator.pop(dialogContext, limit),
                  child: Row(
                    children: [
                      Icon(
                        current == limit
                            ? Icons.radio_button_checked_rounded
                            : Icons.radio_button_off_rounded,
                        color: Theme.of(dialogContext).colorScheme.primary,
                      ),
                      const SizedBox(width: 12),
                      Text('$limit ${limit == 1 ? 'مستخدم' : 'مستخدمين'}'),
                    ],
                  ),
                ),
              )
              .toList(growable: false),
        ),
      );
      if (selected == null || !context.mounted) return;
      await client.putJson(
        ApiEndpoints.connectionLimit,
        data: {'limit': selected},
      );
      ref.invalidate(connectionLimitProvider);
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'تم تحديد الحد بـ $selected اتصالات. سيتم رفض أي اتصال جديد فوقه.',
          ),
        ),
      );
    } catch (_) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر تحديث عدد المستخدمين الآن.')),
      );
    }
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

  Future<void> _sendFeedback(BuildContext context, WidgetRef ref) async {
    final result = await showDialog<_FeedbackDraft>(
      context: context,
      builder: (_) => const _FeedbackDialog(),
    );
    if (result == null ||
        result.message.length < 3 ||
        result.phone.length < 7 ||
        result.networkName.length < 2) {
      return;
    }
    try {
      await ref
          .read(apiClientProvider)
          .postJson(ApiEndpoints.feedback, data: result.toJson());
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('وصلتنا رسالتك، شكرًا لك.')),
        );
      }
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('تعذر إرسال الرسالة الآن. حاول لاحقًا.'),
          ),
        );
      }
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

class _FeedbackDraft {
  const _FeedbackDraft({
    required this.phone,
    required this.networkName,
    required this.message,
  });

  final String phone;
  final String networkName;
  final String message;

  Map<String, String> toJson() => {
    'phone': phone,
    'network_name': networkName,
    'message': message,
  };
}

class _FeedbackDialog extends StatefulWidget {
  const _FeedbackDialog();

  @override
  State<_FeedbackDialog> createState() => _FeedbackDialogState();
}

class _FeedbackDialogState extends State<_FeedbackDialog> {
  final _phone = TextEditingController();
  final _network = TextEditingController();
  final _message = TextEditingController();

  @override
  void dispose() {
    _phone.dispose();
    _network.dispose();
    _message.dispose();
    super.dispose();
  }

  void _close([_FeedbackDraft? result]) {
    FocusManager.instance.primaryFocus?.unfocus();
    Navigator.of(context).pop(result);
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
      title: const Text('تواصل معنا'),
      content: SizedBox(
        width: MediaQuery.sizeOf(context).width - 72,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                maxLength: 32,
                decoration: const InputDecoration(
                  labelText: 'رقم الجوال',
                  hintText: 'مثال: 05xxxxxxxx',
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _network,
                maxLength: 120,
                decoration: const InputDecoration(
                  labelText: 'اسم الشبكة المستخدمة',
                  hintText: 'مثال: شبكة حي النور',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _message,
                minLines: 3,
                maxLines: 5,
                maxLength: 4000,
                textInputAction: TextInputAction.newline,
                decoration: const InputDecoration(
                  hintText: 'اكتب رسالتك بالتفصيل...',
                ),
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _close, child: const Text('إلغاء')),
        FilledButton(
          onPressed: () => _close(
            _FeedbackDraft(
              phone: _phone.text.trim(),
              networkName: _network.text.trim(),
              message: _message.text.trim(),
            ),
          ),
          child: const Text('إرسال المشكلة'),
        ),
      ],
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
  const _PreferenceSwitch({required this.title, required this.preference});

  final String title;
  final NotificationPreference preference;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final settings = ref.watch(notificationPreferencesProvider);
    final value = switch (preference) {
      NotificationPreference.subscriptionAlerts => settings.subscriptionAlerts,
      NotificationPreference.deviceAlerts => settings.deviceAlerts,
      NotificationPreference.systemMessages => settings.systemMessages,
    };
    return SwitchListTile(
      value: value,
      onChanged: (next) async {
        await ref
            .read(notificationPreferencesProvider.notifier)
            .update(preference, next);
        await ref.read(authControllerProvider.notifier).syncPushPreferences();
      },
      title: Text(title),
    );
  }
}
