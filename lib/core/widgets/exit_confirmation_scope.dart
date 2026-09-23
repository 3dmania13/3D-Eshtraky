import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Prevents Android back from closing an authenticated page without asking.
class ExitConfirmationScope extends StatefulWidget {
  const ExitConfirmationScope({required this.child, super.key});

  final Widget child;

  @override
  State<ExitConfirmationScope> createState() => _ExitConfirmationScopeState();
}

class _ExitConfirmationScopeState extends State<ExitConfirmationScope> {
  var _dialogOpen = false;

  Future<void> _confirmExit() async {
    if (_dialogOpen || !mounted) return;
    _dialogOpen = true;
    try {
      final shouldExit = await showDialog<bool>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: const Text('الخروج من التطبيق'),
          content: const Text('هل تريد الخروج من التطبيق؟'),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext, false),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, true),
              child: const Text('خروج'),
            ),
          ],
        ),
      );
      if (shouldExit == true) await SystemNavigator.pop();
    } finally {
      _dialogOpen = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    // A page opened with context.push() (for example, notifications from the
    // dashboard) must return to the page below it. Only the root page should
    // turn Android back into an app-exit confirmation.
    final canReturnToPreviousPage = Navigator.of(context).canPop();
    return PopScope(
      canPop: canReturnToPreviousPage,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && !canReturnToPreviousPage) {
          unawaited(_confirmExit());
        }
      },
      child: widget.child,
    );
  }
}
