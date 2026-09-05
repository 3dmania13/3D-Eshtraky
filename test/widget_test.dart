import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:three_d_subscriber/app.dart';
import 'package:three_d_subscriber/core/auth/token_storage.dart';
import 'package:three_d_subscriber/features/auth/application/auth_controller.dart';

void main() {
  testWidgets('shows the Arabic login screen when there is no session', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tokenStorageProvider.overrideWithValue(MemoryTokenStorage()),
        ],
        child: const SubscriberApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('أهلًا بك'), findsOneWidget);
    expect(find.text('الرمز'), findsOneWidget);
    expect(find.text('تسجيل الدخول'), findsOneWidget);
    expect(find.text('تسجيل الدخول للشبكة'), findsOneWidget);
    expect(find.textContaining('demo001'), findsNothing);
    expect(find.text('كلمة المرور'), findsNothing);

    await tester.tap(find.byTooltip('تفعيل الوضع الداكن'));
    await tester.pumpAndSettle();
    expect(
      Theme.of(tester.element(find.text('أهلًا بك'))).brightness,
      Brightness.dark,
    );
  });
}
