import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../strings/app_strings.dart';
import '../theme/app_theme.dart';
import 'theme_toggle_button.dart';

class MainScaffold extends StatelessWidget {
  const MainScaffold({
    required this.title,
    required this.currentIndex,
    required this.body,
    this.actions,
    super.key,
  });

  final String title;
  final int currentIndex;
  final Widget body;
  final List<Widget>? actions;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 900;
        return Scaffold(
          extendBody: !wide,
          appBar: AppBar(
            toolbarHeight: wide ? 76 : 64,
            titleSpacing: wide ? 28 : 20,
            title: Text(title),
            actions: [
              ...?actions,
              const ThemeToggleButton(),
              const SizedBox(width: 10),
            ],
          ),
          body: Row(
            children: [
              if (wide) _DesktopNavigation(currentIndex: currentIndex),
              Expanded(
                child: AppBackground(
                  child: SafeArea(
                    top: false,
                    child: AnimatedSwitcher(
                      duration: const Duration(milliseconds: 420),
                      switchInCurve: Curves.easeOutCubic,
                      transitionBuilder: (child, animation) => FadeTransition(
                        opacity: animation,
                        child: SlideTransition(
                          position: Tween<Offset>(
                            begin: const Offset(0, .025),
                            end: Offset.zero,
                          ).animate(animation),
                          child: child,
                        ),
                      ),
                      child: KeyedSubtree(
                        key: ValueKey(currentIndex),
                        child: body,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
          bottomNavigationBar: wide
              ? null
              : DecoratedBox(
                  decoration: BoxDecoration(
                    color: colors.surface.withValues(alpha: .96),
                    border: Border(
                      top: BorderSide(color: colors.outlineVariant),
                    ),
                    boxShadow: const [
                      BoxShadow(
                        color: Color(0x14075DE7),
                        blurRadius: 24,
                        offset: Offset(0, -6),
                      ),
                    ],
                  ),
                  child: SafeArea(
                    top: false,
                    child: NavigationBar(
                      selectedIndex: currentIndex,
                      onDestinationSelected: (index) => _go(context, index),
                      destinations: _destinations,
                    ),
                  ),
                ),
        );
      },
    );
  }

  static const _paths = ['/', '/usage', '/devices', '/account'];

  static const _destinations = [
    NavigationDestination(
      icon: Icon(Icons.space_dashboard_outlined),
      selectedIcon: Icon(Icons.space_dashboard_rounded),
      label: AppStrings.home,
    ),
    NavigationDestination(
      icon: Icon(Icons.donut_large_outlined),
      selectedIcon: Icon(Icons.donut_large_rounded),
      label: AppStrings.myUsage,
    ),
    NavigationDestination(
      icon: Icon(Icons.devices_other_outlined),
      selectedIcon: Icon(Icons.devices_other_rounded),
      label: AppStrings.myDevices,
    ),
    NavigationDestination(
      icon: Icon(Icons.person_outline_rounded),
      selectedIcon: Icon(Icons.person_rounded),
      label: AppStrings.account,
    ),
  ];

  static void _go(BuildContext context, int index) => context.go(_paths[index]);
}

class _DesktopNavigation extends StatelessWidget {
  const _DesktopNavigation({required this.currentIndex});

  final int currentIndex;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Container(
      width: 112,
      decoration: BoxDecoration(
        color: colors.surface,
        border: Border(left: BorderSide(color: colors.outlineVariant)),
      ),
      child: NavigationRail(
        selectedIndex: currentIndex,
        onDestinationSelected: (index) => MainScaffold._go(context, index),
        groupAlignment: -.2,
        labelType: NavigationRailLabelType.all,
        leading: Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 18),
          child: Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [AppTheme.primary, AppTheme.secondary],
                begin: Alignment.topRight,
                end: Alignment.bottomLeft,
              ),
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x33075DE7),
                  blurRadius: 16,
                  offset: Offset(0, 7),
                ),
              ],
            ),
            alignment: Alignment.center,
            child: const Text(
              '3D',
              textDirection: TextDirection.ltr,
              style: TextStyle(
                color: Colors.white,
                fontSize: 18,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        ),
        destinations: const [
          NavigationRailDestination(
            icon: Icon(Icons.space_dashboard_outlined),
            selectedIcon: Icon(Icons.space_dashboard_rounded),
            label: Text(AppStrings.home),
          ),
          NavigationRailDestination(
            icon: Icon(Icons.donut_large_outlined),
            selectedIcon: Icon(Icons.donut_large_rounded),
            label: Text(AppStrings.myUsage),
          ),
          NavigationRailDestination(
            icon: Icon(Icons.devices_other_outlined),
            selectedIcon: Icon(Icons.devices_other_rounded),
            label: Text(AppStrings.myDevices),
          ),
          NavigationRailDestination(
            icon: Icon(Icons.person_outline_rounded),
            selectedIcon: Icon(Icons.person_rounded),
            label: Text(AppStrings.account),
          ),
        ],
      ),
    );
  }
}

class AppBackground extends StatelessWidget {
  const AppBackground({required this.child, super.key});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: dark
              ? const [Color(0xFF0B1220), Color(0xFF101A2D)]
              : const [Color(0xFFF8FAFF), Color(0xFFF2F7FF)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          PositionedDirectional(
            top: -120,
            end: -100,
            child: _Glow(
              color: dark ? const Color(0x1718BCEB) : const Color(0x1718BCEB),
              size: 300,
            ),
          ),
          PositionedDirectional(
            bottom: -150,
            start: -120,
            child: _Glow(
              color: dark ? const Color(0x19075DE7) : const Color(0x12075DE7),
              size: 340,
            ),
          ),
          child,
        ],
      ),
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow({required this.color, required this.size});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
  );
}

class PageFrame extends StatelessWidget {
  const PageFrame({
    required this.child,
    super.key,
    this.padding = const EdgeInsets.all(16),
  });

  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) => Align(
        alignment: Alignment.topCenter,
        child: SizedBox(
          width: constraints.maxWidth > 1120 ? 1120 : constraints.maxWidth,
          height: constraints.maxHeight,
          child: Padding(
            padding: constraints.maxWidth >= 700
                ? const EdgeInsets.fromLTRB(28, 18, 28, 28)
                : padding,
            child: _PageReveal(child: child),
          ),
        ),
      ),
    );
  }
}

class _PageReveal extends StatefulWidget {
  const _PageReveal({required this.child});

  final Widget child;

  @override
  State<_PageReveal> createState() => _PageRevealState();
}

class _PageRevealState extends State<_PageReveal> {
  var _visible = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) setState(() => _visible = true);
    });
  }

  @override
  Widget build(BuildContext context) => AnimatedOpacity(
    opacity: _visible ? 1 : 0,
    duration: const Duration(milliseconds: 420),
    curve: Curves.easeOut,
    child: AnimatedSlide(
      offset: _visible ? Offset.zero : const Offset(0, .025),
      duration: const Duration(milliseconds: 480),
      curve: Curves.easeOutCubic,
      child: widget.child,
    ),
  );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key, this.trailing});

  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: Theme.of(
              context,
            ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
          ),
        ),
        ?trailing,
      ],
    );
  }
}

class StatusBadge extends StatelessWidget {
  const StatusBadge({required this.label, required this.isPositive, super.key});

  final String label;
  final bool isPositive;

  @override
  Widget build(BuildContext context) {
    final color = isPositive
        ? const Color(0xFF18864B)
        : const Color(0xFFB54708);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 7,
              height: 7,
              decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            ),
            const SizedBox(width: 6),
            Text(
              label,
              style: TextStyle(
                color: color,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
