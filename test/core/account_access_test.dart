import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:three_d_subscriber/core/auth/account_access.dart';

void main() {
  String token(String role) =>
      'header.${base64Url.encode(utf8.encode(jsonEncode({'role': role})))}.signature';

  test('broadband uses shared services through its own account endpoints', () {
    for (final service in [
      'dashboard',
      'usage/summary',
      'devices',
      'recharges',
      'notifications',
      'notifications/push-token',
      'feedback',
      'speed',
      'connection-limit',
    ]) {
      final path = '/api/v1/subscriber/$service';
      expect(
        accountApiPath(path, token('broadband')),
        '/api/v1/broadband/$service',
      );
      expect(accountApiPath(path, token('subscriber')), path);
      expect(accountApiPath(path, null), path);
    }
    expect(
      accountApiPath('/api/v1/auth/broadband-refresh', token('broadband')),
      '/api/v1/auth/broadband-refresh',
    );
  });
}
