import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/theme_toggle_button.dart';
import '../application/auth_controller.dart';

class BroadbandLoginScreen extends ConsumerStatefulWidget {
  const BroadbandLoginScreen({super.key});
  @override
  ConsumerState<BroadbandLoginScreen> createState() =>
      _BroadbandLoginScreenState();
}

class _BroadbandLoginScreenState extends ConsumerState<BroadbandLoginScreen> {
  final _form = GlobalKey<FormState>();
  final _username = TextEditingController();
  @override
  void dispose() {
    _username.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    await ref
        .read(authControllerProvider.notifier)
        .login(code: _username.text.trim(), broadband: true);
    // The shared router opens the same dashboard as code-login accounts.
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final busy = auth.status == AuthStatus.authenticating;
    return Scaffold(
      appBar: AppBar(
        title: const Text('دخول البرودباند'),
        actions: const [ThemeToggleButton()],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Form(
                  key: _form,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Icon(
                        Icons.router_rounded,
                        size: 58,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                      const SizedBox(height: 20),
                      Text(
                        'تسجيل دخول البرودباند',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'أدخل اسم المستخدم للوصول إلى حسابك وجميع الخدمات.',
                      ),
                      const SizedBox(height: 24),
                      TextFormField(
                        key: const Key('broadband-username'),
                        controller: _username,
                        enabled: !busy,
                        maxLength: 64,
                        autocorrect: false,
                        enableSuggestions: false,
                        textDirection: TextDirection.ltr,
                        textInputAction: TextInputAction.done,
                        onFieldSubmitted: (_) {
                          if (!busy) _login();
                        },
                        decoration: const InputDecoration(
                          labelText: 'اسم مستخدم البرودباند',
                          prefixIcon: Icon(Icons.person_outline),
                        ),
                        validator: (value) =>
                            value == null || value.trim().isEmpty
                            ? 'أدخل اسم المستخدم.'
                            : null,
                      ),
                      const SizedBox(height: 16),
                      FilledButton(
                        onPressed: busy ? null : _login,
                        child: const Text('دخول'),
                      ),
                      if (busy)
                        const Padding(
                          padding: EdgeInsets.only(top: 16),
                          child: LinearProgressIndicator(),
                        ),
                      if (auth.errorMessage != null)
                        Padding(
                          padding: const EdgeInsets.only(top: 16),
                          child: Text(
                            auth.errorMessage!,
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.error,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
