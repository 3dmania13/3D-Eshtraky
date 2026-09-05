import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/strings/app_strings.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/theme_toggle_button.dart';
import '../application/auth_controller.dart';
import 'network_login_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _codeController = TextEditingController();
  late final AnimationController _animation;

  @override
  void initState() {
    super.initState();
    _animation = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..forward();
  }

  @override
  void dispose() {
    _animation.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!_formKey.currentState!.validate()) return;
    await ref
        .read(authControllerProvider.notifier)
        .login(code: _codeController.text);
  }

  Future<void> _openNetworkLogin() => Navigator.of(
    context,
  ).push(MaterialPageRoute<void>(builder: (_) => const NetworkLoginScreen()));

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final loading =
        auth.status == AuthStatus.authenticating ||
        auth.status == AuthStatus.restoring;
    return Scaffold(
      body: LayoutBuilder(
        builder: (context, constraints) {
          final desktop = constraints.maxWidth >= 850;
          return Stack(
            fit: StackFit.expand,
            children: [
              const _LoginBackdrop(),
              const PositionedDirectional(
                top: 14,
                end: 16,
                child: SafeArea(child: ThemeToggleButton()),
              ),
              SafeArea(
                child: Center(
                  child: SingleChildScrollView(
                    padding: EdgeInsets.all(desktop ? 40 : 22),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 1080),
                      child: desktop
                          ? Row(
                              children: [
                                const Expanded(child: _BrandPanel()),
                                const SizedBox(width: 54),
                                SizedBox(
                                  width: 430,
                                  child: _LoginCard(
                                    formKey: _formKey,
                                    controller: _codeController,
                                    loading: loading,
                                    error: auth.errorMessage,
                                    onSubmit: _submit,
                                    onNetworkLogin: _openNetworkLogin,
                                    animation: _animation,
                                  ),
                                ),
                              ],
                            )
                          : _LoginCard(
                              formKey: _formKey,
                              controller: _codeController,
                              loading: loading,
                              error: auth.errorMessage,
                              onSubmit: _submit,
                              onNetworkLogin: _openNetworkLogin,
                              animation: _animation,
                              showLogo: true,
                            ),
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _LoginBackdrop extends StatelessWidget {
  const _LoginBackdrop();

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: dark
              ? const [Color(0xFF0B1220), Color(0xFF111D32), Color(0xFF0D2231)]
              : const [Color(0xFFEEF5FF), Color(0xFFF9FBFF), Color(0xFFEAF9FF)],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
      ),
      child: Stack(
        children: [
          PositionedDirectional(
            top: -90,
            end: -70,
            child: _orb(const Color(0x3018BCEB), 260),
          ),
          PositionedDirectional(
            bottom: -130,
            start: -100,
            child: _orb(const Color(0x26075DE7), 330),
          ),
        ],
      ),
    );
  }

  static Widget _orb(Color color, double size) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
  );
}

class _BrandPanel extends StatelessWidget {
  const _BrandPanel();

  @override
  Widget build(BuildContext context) {
    final surface = Theme.of(context).colorScheme.surface;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 190,
          height: 190,
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: surface.withValues(alpha: .86),
            borderRadius: BorderRadius.circular(48),
            boxShadow: const [
              BoxShadow(
                color: Color(0x22075DE7),
                blurRadius: 40,
                offset: Offset(0, 18),
              ),
            ],
          ),
          child: Image.asset('Assets/Eshtraky.png'),
        ),
        const SizedBox(height: 34),
        Text(
          'كل تفاصيل اشتراكك\nفي مكان واحد',
          style: Theme.of(
            context,
          ).textTheme.headlineMedium?.copyWith(fontSize: 36, height: 1.35),
        ),
        const SizedBox(height: 14),
        Text(
          'راقب استهلاكك، أجهزتك وسرعة اتصالك بسهولة وأمان.',
          style: Theme.of(
            context,
          ).textTheme.bodyLarge?.copyWith(color: const Color(0xFF61708A)),
        ),
      ],
    );
  }
}

class _LoginCard extends StatelessWidget {
  const _LoginCard({
    required this.formKey,
    required this.controller,
    required this.loading,
    required this.error,
    required this.onSubmit,
    required this.onNetworkLogin,
    required this.animation,
    this.showLogo = false,
  });

  final GlobalKey<FormState> formKey;
  final TextEditingController controller;
  final bool loading;
  final String? error;
  final VoidCallback onSubmit;
  final VoidCallback onNetworkLogin;
  final Animation<double> animation;
  final bool showLogo;

  @override
  Widget build(BuildContext context) {
    final fade = CurvedAnimation(parent: animation, curve: Curves.easeOutCubic);
    final colors = Theme.of(context).colorScheme;
    return FadeTransition(
      opacity: fade,
      child: SlideTransition(
        position: Tween<Offset>(
          begin: const Offset(0, .08),
          end: Offset.zero,
        ).animate(fade),
        child: Container(
          padding: EdgeInsets.all(
            MediaQuery.sizeOf(context).width < 380 ? 22 : 30,
          ),
          decoration: BoxDecoration(
            color: colors.surface.withValues(alpha: .94),
            borderRadius: BorderRadius.circular(30),
            border: Border.all(color: colors.outlineVariant),
            boxShadow: const [
              BoxShadow(
                color: Color(0x1F075DE7),
                blurRadius: 42,
                offset: Offset(0, 18),
              ),
            ],
          ),
          child: Form(
            key: formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (showLogo) ...[
                  Align(
                    alignment: Alignment.centerRight,
                    child: Container(
                      width: 82,
                      height: 82,
                      padding: const EdgeInsets.all(5),
                      decoration: BoxDecoration(
                        color: colors.surface,
                        borderRadius: BorderRadius.circular(24),
                        boxShadow: const [
                          BoxShadow(color: Color(0x1A075DE7), blurRadius: 20),
                        ],
                      ),
                      child: Image.asset('Assets/Eshtraky.png'),
                    ),
                  ),
                  const SizedBox(height: 24),
                ],
                Text(
                  AppStrings.loginTitle,
                  style: Theme.of(context).textTheme.headlineMedium,
                ),
                const SizedBox(height: 8),
                Text(
                  AppStrings.loginSubtitle,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    color: const Color(0xFF66758E),
                  ),
                ),
                const SizedBox(height: 28),
                TextFormField(
                  controller: controller,
                  enabled: !loading,
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.right,
                  autofillHints: const [AutofillHints.username],
                  onFieldSubmitted: (_) => onSubmit(),
                  decoration: const InputDecoration(
                    labelText: AppStrings.accessCode,
                    prefixIcon: Icon(
                      Icons.key_rounded,
                      color: AppTheme.primary,
                    ),
                  ),
                  validator: (value) => value == null || value.trim().isEmpty
                      ? 'أدخل الرمز.'
                      : null,
                ),
                AnimatedSize(
                  duration: const Duration(milliseconds: 250),
                  child: error == null
                      ? const SizedBox(height: 0)
                      : Padding(
                          padding: const EdgeInsets.only(top: 12),
                          child: Text(
                            error!,
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.error,
                            ),
                          ),
                        ),
                ),
                const SizedBox(height: 22),
                FilledButton.icon(
                  onPressed: loading ? null : onSubmit,
                  icon: loading
                      ? const SizedBox(
                          width: 21,
                          height: 21,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: Colors.white,
                          ),
                        )
                      : const Icon(Icons.arrow_back_rounded),
                  label: const Text(AppStrings.login),
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  key: const Key('network-login-button'),
                  onPressed: loading ? null : onNetworkLogin,
                  icon: const Icon(Icons.wifi_rounded),
                  label: const Text('تسجيل الدخول للشبكة'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
