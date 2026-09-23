import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/account/presentation/account_screen.dart';
import '../../features/admins/presentation/admins_screen.dart';
import '../../features/auth/application/auth_controller.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/dashboard/presentation/dashboard_screen.dart';
import '../../features/devices/presentation/devices_screen.dart';
import '../../features/file_transfer/presentation/file_transfer_placeholder_screen.dart';
import '../../features/notifications/presentation/notifications_screen.dart';
import '../../features/recharges/presentation/recharges_screen.dart';
import '../../features/subscriber/presentation/subscription_screen.dart';
import '../../features/usage/presentation/usage_screen.dart';
import '../widgets/exit_confirmation_scope.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>();

final appRouterProvider = Provider<GoRouter>((ref) {
  final authStatus = ref.watch(
    authControllerProvider.select((state) => state.status),
  );
  final router = GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/login',
    redirect: (context, state) {
      final onLogin = state.matchedLocation == '/login';
      if (authStatus == AuthStatus.authenticated && onLogin) return '/';
      if (authStatus == AuthStatus.unauthenticated && !onLogin) return '/login';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(
        path: '/',
        builder: (_, _) =>
            const ExitConfirmationScope(child: DashboardScreen()),
      ),
      GoRoute(
        path: '/admins',
        builder: (_, _) => const ExitConfirmationScope(child: AdminsScreen()),
      ),
      GoRoute(
        path: '/subscription',
        builder: (_, _) =>
            const ExitConfirmationScope(child: SubscriptionScreen()),
      ),
      GoRoute(
        path: '/usage',
        builder: (_, _) => const ExitConfirmationScope(child: UsageScreen()),
      ),
      GoRoute(
        path: '/devices',
        builder: (_, _) => const ExitConfirmationScope(child: DevicesScreen()),
      ),
      GoRoute(
        path: '/recharges',
        builder: (_, _) =>
            const ExitConfirmationScope(child: RechargesScreen()),
      ),
      GoRoute(
        path: '/notifications',
        builder: (_, _) =>
            const ExitConfirmationScope(child: NotificationsScreen()),
      ),
      GoRoute(
        path: '/account',
        builder: (_, _) => const ExitConfirmationScope(child: AccountScreen()),
      ),
      GoRoute(
        path: '/file-transfer',
        builder: (_, _) =>
            const ExitConfirmationScope(child: FileTransferPlaceholderScreen()),
      ),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
