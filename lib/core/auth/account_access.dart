import 'dart:convert';

// Client routing only; the server verifies the signature and account role.
bool isBroadbandToken(String? token) {
  try {
    final payload = jsonDecode(
      utf8.decode(base64Url.decode(base64Url.normalize(token!.split('.')[1]))),
    );
    return payload is Map && payload['role'] == 'broadband';
  } catch (_) {
    return false;
  }
}

String accountApiPath(String path, String? token) =>
    isBroadbandToken(token) && path.startsWith('/api/v1/subscriber/')
    ? path.replaceFirst('/api/v1/subscriber/', '/api/v1/broadband/')
    : path;
