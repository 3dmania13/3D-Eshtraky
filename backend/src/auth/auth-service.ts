import type { AuthRepository } from '../domain/contracts.js';
import type { SubscriberPrincipal, SubscriberState } from '../domain/models.js';
import { AppError, invalidCredentials } from '../errors.js';
import { SubscriptionService } from '../services/subscription-service.js';
import { normalizeUsername } from '../utils/values.js';
import { createRefreshToken, hashRefreshToken, passwordMatches } from './tokens.js';

const DUMMY_PASSWORD = 'subscriber-auth-dummy-comparison-value';

export interface AccessTokenSigner {
  sign(principal: SubscriberPrincipal): string;
}

export interface AuthResult {
  readonly accessToken: string;
  readonly refreshToken: string;
  readonly subscriber: {
    readonly username: string;
    readonly status: SubscriberState;
  };
}

export class AuthService {
  constructor(
    private readonly repository: AuthRepository,
    private readonly subscriptions: SubscriptionService,
    private readonly signer: AccessTokenSigner,
    private readonly refreshTokenDays: number,
    private readonly now: () => Date = () => new Date(),
  ) {}

  async login(usernameInput: string, password: string): Promise<AuthResult> {
    const username = normalizeUsername(usernameInput);
    if (password.length < 1 || password.length > 253) throw invalidCredentials();

    const record = await this.repository.findForAuthentication(username);
    const expected = record?.cleartextPassword ?? DUMMY_PASSWORD;
    const matches = passwordMatches(password, expected);
    if (!record || !record.cleartextPassword || !matches) throw invalidCredentials();

    return this.issue(record, this.subscriptions.state(record, this.now()));
  }

  async loginWithCode(codeInput: string): Promise<AuthResult> {
    const username = normalizeUsername(codeInput);
    const record = await this.repository.findForAuthentication(username);
    if (!record) {
      throw new AppError(401, 'INVALID_CODE', 'الرمز غير صحيح.');
    }
    return this.issue(record, this.subscriptions.state(record, this.now()));
  }

  async refresh(refreshToken: string): Promise<AuthResult> {
    if (!/^[A-Za-z0-9_-]{32,256}$/.test(refreshToken)) throw invalidCredentials();
    const replacement = createRefreshToken();
    const record = await this.repository.rotateRefreshToken({
      currentHash: hashRefreshToken(refreshToken),
      replacementHash: hashRefreshToken(replacement),
      replacementExpiresAt: this.refreshExpiry(),
    });
    if (!record) throw invalidCredentials();
    const status = this.requireActive(this.subscriptions.state(record, this.now()));
    const principal = { username: record.username, status } as const;
    return {
      accessToken: this.signer.sign(principal),
      refreshToken: replacement,
      subscriber: principal,
    };
  }

  async logout(refreshToken: string): Promise<void> {
    if (refreshToken) await this.repository.revokeRefreshToken(hashRefreshToken(refreshToken));
  }

  private async issue(
    record: Awaited<ReturnType<AuthRepository['findForAuthentication']>> & {},
    state: SubscriberState,
  ): Promise<AuthResult> {
    const status = this.requireActive(state);
    const refreshToken = createRefreshToken();
    await this.repository.issueRefreshToken({
      subscriberUsername: record.username,
      tokenHash: hashRefreshToken(refreshToken),
      expiresAt: this.refreshExpiry(),
    });
    const principal = { username: record.username, status } as const;
    return {
      accessToken: this.signer.sign(principal),
      refreshToken,
      subscriber: principal,
    };
  }

  private requireActive(state: SubscriberState): 'active' {
    if (state === 'disabled') {
      throw new AppError(403, 'ACCOUNT_DISABLED', 'الحساب معطل.');
    }
    if (state === 'expired') {
      throw new AppError(403, 'ACCOUNT_EXPIRED', 'انتهت صلاحية الاشتراك.');
    }
    return 'active';
  }

  private refreshExpiry(): Date {
    return new Date(this.now().getTime() + this.refreshTokenDays * 86_400_000);
  }
}
