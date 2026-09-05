import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/auth/token_storage.dart';
import '../../../core/config/app_config.dart';
import '../../../core/errors/app_exception.dart';
import '../../subscriber/domain/subscriber.dart';
import '../data/auth_repository.dart';

enum AuthStatus { restoring, unauthenticated, authenticating, authenticated }

class AuthState {
  const AuthState({required this.status, this.subscriber, this.errorMessage});

  const AuthState.restoring() : this(status: AuthStatus.restoring);

  final AuthStatus status;
  final Subscriber? subscriber;
  final String? errorMessage;

  bool get isAuthenticated => status == AuthStatus.authenticated;
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._repository, this._tokenStorage)
    : super(const AuthState.restoring()) {
    restore();
  }

  final AuthRepository _repository;
  final TokenStorage _tokenStorage;

  Future<void> restore() async {
    try {
      final accessToken = await _tokenStorage.readAccessToken();
      final refreshToken = await _tokenStorage.readRefreshToken();
      if ((accessToken == null || accessToken.isEmpty) &&
          (refreshToken == null || refreshToken.isEmpty)) {
        state = const AuthState(status: AuthStatus.unauthenticated);
        return;
      }
      final subscriber = await _repository.restoreSubscriber();
      state = AuthState(
        status: AuthStatus.authenticated,
        subscriber: subscriber,
      );
    } catch (_) {
      await _tokenStorage.clear();
      state = const AuthState(status: AuthStatus.unauthenticated);
    }
  }

  Future<bool> login({required String code}) async {
    state = const AuthState(status: AuthStatus.authenticating);
    try {
      final session = await _repository.login(code: code);
      await _tokenStorage.writeTokens(
        StoredTokens(
          accessToken: session.accessToken,
          refreshToken: session.refreshToken,
        ),
      );
      final subscriber = await _repository.restoreSubscriber();
      state = AuthState(
        status: AuthStatus.authenticated,
        subscriber: subscriber,
      );
      return true;
    } on AppException catch (error) {
      await _tokenStorage.clear();
      state = AuthState(
        status: AuthStatus.unauthenticated,
        errorMessage: error.message,
      );
      return false;
    } catch (_) {
      await _tokenStorage.clear();
      state = const AuthState(
        status: AuthStatus.unauthenticated,
        errorMessage: 'تعذر إكمال تسجيل الدخول. حاول مرة أخرى.',
      );
      return false;
    }
  }

  Future<void> logout() async {
    final refreshToken = await _tokenStorage.readRefreshToken();
    if (refreshToken != null && refreshToken.isNotEmpty) {
      try {
        await _repository.logout(refreshToken);
      } catch (_) {
        // Local logout must succeed even if the API is unavailable.
      }
    }
    await expireSession();
  }

  Future<void> expireSession() async {
    await _tokenStorage.clear();
    state = const AuthState(status: AuthStatus.unauthenticated);
  }
}

final Provider<TokenStorage> tokenStorageProvider = Provider<TokenStorage>(
  (ref) => SecureTokenStorage(),
);

final Provider<ApiClient> apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(
    tokenStorage: ref.watch(tokenStorageProvider),
    onSessionExpired: () =>
        ref.read(authControllerProvider.notifier).expireSession(),
  ),
);

final Provider<AuthRepository> authRepositoryProvider =
    Provider<AuthRepository>(
      (ref) => AppConfig.useMockData
          ? const MockAuthRepository()
          : ApiAuthRepository(ref.watch(apiClientProvider)),
    );

final StateNotifierProvider<AuthController, AuthState> authControllerProvider =
    StateNotifierProvider<AuthController, AuthState>(
      (ref) => AuthController(
        ref.watch(authRepositoryProvider),
        ref.watch(tokenStorageProvider),
      ),
    );
