import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../../core/errors/app_exception.dart';
import '../../../core/mock/mock_data.dart';
import '../../subscriber/domain/subscriber.dart';

class AuthSession {
  const AuthSession({required this.accessToken, required this.refreshToken});

  final String accessToken;
  final String refreshToken;
}

abstract interface class AuthRepository {
  Future<AuthSession> login({required String code, bool broadband = false});
  Future<Subscriber> restoreSubscriber();
  Future<void> logout(String refreshToken);
}

class ApiAuthRepository implements AuthRepository {
  const ApiAuthRepository(this._client);

  final ApiClient _client;

  @override
  Future<AuthSession> login({
    required String code,
    bool broadband = false,
  }) async {
    final json = await _client.postJson(
      broadband ? '/api/v1/auth/broadband-login' : ApiEndpoints.codeLogin,
      data: {broadband ? 'username' : 'code': code.trim()},
    );
    final accessToken = json['accessToken'];
    final refreshToken = json['refreshToken'];
    if (accessToken is! String ||
        accessToken.isEmpty ||
        refreshToken is! String ||
        refreshToken.isEmpty) {
      throw const AppException('استجابة تسجيل الدخول غير صالحة.');
    }
    return AuthSession(accessToken: accessToken, refreshToken: refreshToken);
  }

  @override
  Future<void> logout(String refreshToken) async {
    await _client.postJson(
      refreshToken.startsWith('bb_')
          ? '/api/v1/auth/broadband-logout'
          : ApiEndpoints.logout,
      data: {'refreshToken': refreshToken},
    );
  }

  @override
  Future<Subscriber> restoreSubscriber() async {
    return Subscriber.fromJson(await _client.getJson(ApiEndpoints.dashboard));
  }
}

class MockAuthRepository implements AuthRepository {
  const MockAuthRepository();

  @override
  Future<AuthSession> login({
    required String code,
    bool broadband = false,
  }) async {
    await Future<void>.delayed(const Duration(milliseconds: 850));
    if (code.trim() != 'demo001') {
      throw const AppException('الرمز غير صحيح.', code: 'invalid_credentials');
    }
    return const AuthSession(
      accessToken: 'mock-access-token',
      refreshToken: 'mock-refresh-token',
    );
  }

  @override
  Future<void> logout(String refreshToken) async {}

  @override
  Future<Subscriber> restoreSubscriber() async {
    await Future<void>.delayed(const Duration(milliseconds: 250));
    return MockData.subscriber(DateTime.now());
  }
}
