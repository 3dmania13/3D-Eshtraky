import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

const _networkLoginUrl = 'http://z.net/login';

class NetworkLoginScreen extends StatefulWidget {
  const NetworkLoginScreen({super.key});

  @override
  State<NetworkLoginScreen> createState() => _NetworkLoginScreenState();
}

class _NetworkLoginScreenState extends State<NetworkLoginScreen> {
  WebViewController? _controller;
  var _loading = true;
  String? _error;

  bool get _supportsInAppWebView =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  @override
  void initState() {
    super.initState();
    if (_supportsInAppWebView) {
      _controller = WebViewController()
        ..setJavaScriptMode(JavaScriptMode.unrestricted)
        ..setNavigationDelegate(
          NavigationDelegate(
            onPageStarted: (_) {
              if (!mounted) return;
              setState(() {
                _loading = true;
                _error = null;
              });
            },
            onPageFinished: (_) {
              if (!mounted) return;
              setState(() => _loading = false);
            },
            onWebResourceError: (error) {
              if (!mounted || error.isForMainFrame != true) return;
              setState(() {
                _loading = false;
                _error = 'تعذر فتح صفحة تسجيل الدخول للشبكة.';
              });
            },
            onNavigationRequest: (request) {
              final uri = Uri.tryParse(request.url);
              if (uri != null &&
                  uri.scheme == 'https' &&
                  (uri.host == 'z.net' || uri.host.endsWith('.z.net'))) {
                _controller?.loadRequest(uri.replace(scheme: 'http'));
                return NavigationDecision.prevent;
              }
              return NavigationDecision.navigate;
            },
          ),
        )
        ..loadRequest(Uri.parse(_networkLoginUrl));
    } else {
      _loading = false;
    }
  }

  Future<void> _openExternal() async {
    final opened = await launchUrl(
      Uri.parse(_networkLoginUrl),
      mode: LaunchMode.externalApplication,
    );
    if (!opened && mounted) {
      setState(() => _error = 'تعذر فتح صفحة تسجيل الدخول للشبكة.');
    }
  }

  Future<void> _retry() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    await _controller?.loadRequest(Uri.parse(_networkLoginUrl));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('تسجيل الدخول للشبكة'),
        actions: [
          if (_controller != null)
            IconButton(
              tooltip: 'إعادة تحميل الصفحة',
              onPressed: _retry,
              icon: const Icon(Icons.refresh_rounded),
            ),
        ],
      ),
      body: Stack(
        children: [
          if (_controller case final controller?)
            WebViewWidget(controller: controller)
          else
            _ExternalBrowserFallback(onOpen: _openExternal),
          if (_loading)
            const Align(
              alignment: Alignment.topCenter,
              child: LinearProgressIndicator(),
            ),
          if (_error case final error?)
            ColoredBox(
              color: Theme.of(context).colorScheme.surface,
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.wifi_off_rounded, size: 48),
                      const SizedBox(height: 12),
                      Text(error, textAlign: TextAlign.center),
                      const SizedBox(height: 16),
                      FilledButton.icon(
                        onPressed: _controller == null ? _openExternal : _retry,
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('إعادة المحاولة'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ExternalBrowserFallback extends StatelessWidget {
  const _ExternalBrowserFallback({required this.onOpen});

  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: FilledButton.icon(
          onPressed: onOpen,
          icon: const Icon(Icons.open_in_browser_rounded),
          label: const Text('فتح صفحة الشبكة'),
        ),
      ),
    );
  }
}
