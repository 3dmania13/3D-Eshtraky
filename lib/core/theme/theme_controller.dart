import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ThemeController extends StateNotifier<ThemeMode> {
  ThemeController() : super(ThemeMode.light) {
    unawaited(_restore());
  }

  static const _preferenceKey = 'dark_theme_enabled';

  Future<void> _restore() async {
    try {
      final preferences = await SharedPreferences.getInstance();
      state = (preferences.getBool(_preferenceKey) ?? false)
          ? ThemeMode.dark
          : ThemeMode.light;
    } catch (_) {
      // Keep the light theme when local preferences are unavailable.
    }
  }

  Future<void> toggle() async {
    final dark = state != ThemeMode.dark;
    state = dark ? ThemeMode.dark : ThemeMode.light;
    try {
      final preferences = await SharedPreferences.getInstance();
      await preferences.setBool(_preferenceKey, dark);
    } catch (_) {
      // The visual change still applies for the current session.
    }
  }
}

final themeModeProvider = StateNotifierProvider<ThemeController, ThemeMode>(
  (ref) => ThemeController(),
);
