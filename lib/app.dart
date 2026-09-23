import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import 'core/routing/app_router.dart';
import 'core/strings/app_strings.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/theme_controller.dart';
import 'core/widgets/main_scaffold.dart';
import 'core/providers/app_providers.dart';
import 'features/notifications/data/push_notification_service.dart';
import 'features/notifications/domain/subscriber_notification.dart';
import 'features/auth/application/auth_controller.dart';

class SubscriberApp extends ConsumerStatefulWidget {
  const SubscriberApp({super.key});

  @override
  ConsumerState<SubscriberApp> createState() => _SubscriberAppState();
}

class _SubscriberAppState extends ConsumerState<SubscriberApp>
    with WidgetsBindingObserver {
  StreamSubscription<void>? _opened;
  StreamSubscription<Object?>? _received;
  bool _broadcastDialogOpen = false;
  bool _uiReady = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final push = FirebasePushNotificationService.instance;
    _received = push.foregroundNotifications.listen((notification) {
      ref.invalidate(notificationsProvider);
      if (notification.type == NotificationType.broadcast) {
        unawaited(_showBroadcastDialog(notification));
      }
    });
    _opened = push.openedNotifications.listen((_) => _openAppHome());
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      _uiReady = true;
      _showPendingBroadcast();
      try {
        if (await push.openedFromNotification()) _openAppHome();
      } catch (_) {}
    });
    ref.listenManual(authControllerProvider, (_, next) {
      if (next.isAuthenticated) _showPendingBroadcast();
    }, fireImmediately: true);
  }

  void _openAppHome() {
    if (!mounted) return;
    ref.invalidate(notificationsProvider);
    ref.read(appRouterProvider).go('/');
  }

  Future<void> _showPendingBroadcast() async {
    if (!mounted || !_uiReady || _broadcastDialogOpen) return;
    final auth = ref.read(authControllerProvider);
    if (!auth.isAuthenticated) return;
    try {
      final notifications = await ref.read(notificationsProvider.future);
      final message = notifications
          .where(
            (item) => item.type == NotificationType.broadcast && !item.isRead,
          )
          .firstOrNull;
      if (message == null || !mounted || _broadcastDialogOpen) return;
      await _showBroadcastDialog(message);
    } catch (_) {
      // A notification failure must never interrupt an authenticated session.
    }
  }

  Future<void> _showBroadcastDialog(SubscriberNotification message) async {
    if (!mounted || !_uiReady || _broadcastDialogOpen) return;
    final dialogContext = rootNavigatorKey.currentContext;
    if (dialogContext == null || !dialogContext.mounted) return;
    _broadcastDialogOpen = true;
    var dismissed = false;
    try {
      await showDialog<void>(
        context: dialogContext,
        barrierDismissible: false,
        builder: (context) => AlertDialog(
          title: Text(message.title),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(message.body),
                if (message.linkTitle != null && message.linkUrl != null)
                  TextButton.icon(
                    onPressed: () => _openBroadcastLink(context, message),
                    icon: const Icon(Icons.open_in_new_rounded),
                    label: Text(message.linkTitle!),
                    style: TextButton.styleFrom(
                      foregroundColor: const Color(0xFF1264DB),
                    ),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('إغلاق'),
            ),
          ],
        ),
      );
      await ref.read(notificationRepositoryProvider).markRead(message.id);
      ref.invalidate(notificationsProvider);
      dismissed = true;
    } catch (_) {
      // A notification failure must never interrupt an authenticated session.
    } finally {
      _broadcastDialogOpen = false;
      if (dismissed && mounted) _showPendingBroadcast();
    }
  }

  Future<void> _openBroadcastLink(
    BuildContext context,
    SubscriberNotification message,
  ) async {
    final uri = Uri.tryParse(message.linkUrl ?? '');
    if (uri == null || !['http', 'https'].contains(uri.scheme)) return;
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) &&
        context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر فتح الرابط في المتصفح.')),
      );
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _showPendingBroadcast();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_opened?.cancel());
    unawaited(_received?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(appRouterProvider);
    final themeMode = ref.watch(themeModeProvider);
    return MaterialApp.router(
      title: AppStrings.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      themeMode: themeMode,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => AppBackground(
        child: Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
      ),
      routerConfig: router,
    );
  }
}
