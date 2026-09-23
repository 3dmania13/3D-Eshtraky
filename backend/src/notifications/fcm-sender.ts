import { createSign } from "node:crypto";
import { readFile } from "node:fs/promises";

export interface PushMessage {
  id: string;
  type: string;
  title: string;
  body: string;
  linkTitle?: string;
  linkUrl?: string;
}

export interface PushDeliveryOptions {
  /** Keep an in-app alert live without creating a status-bar notification. */
  showExternal?: boolean;
}

export interface PushSender {
  send(
    token: string,
    message: PushMessage,
    options?: PushDeliveryOptions,
  ): Promise<void>;
}
interface FcmCredentials {
  project_id: string;
  client_email: string;
  private_key: string;
}
export class PushSendError extends Error {
  constructor(
    readonly code: string,
    readonly permanent = false,
    readonly invalidToken = false,
  ) {
    super(code);
  }
}

export class FcmSender implements PushSender {
  private accessToken = "";
  private expiresAt = 0;
  private credentials?: FcmCredentials;
  private authorization: Promise<string> | undefined;
  constructor(
    private readonly credentialsPath: string,
    private readonly http: typeof fetch = fetch,
  ) {}

  private async loadCredentials(): Promise<FcmCredentials> {
    if (this.credentials) return this.credentials;
    this.credentials = JSON.parse(
      await readFile(this.credentialsPath, "utf8"),
    ) as FcmCredentials;
    return this.credentials;
  }

  private async requestAccessToken(
    credentials: FcmCredentials,
  ): Promise<string> {
    if (Date.now() < this.expiresAt) return this.accessToken;
    const now = Math.floor(Date.now() / 1000);
    const encode = (value: object) =>
      Buffer.from(JSON.stringify(value)).toString("base64url");
    const unsigned = `${encode({ alg: "RS256", typ: "JWT" })}.${encode({
      iss: credentials.client_email,
      scope: "https://www.googleapis.com/auth/firebase.messaging",
      aud: "https://oauth2.googleapis.com/token",
      iat: now,
      exp: now + 3600,
    })}`;
    const signature = createSign("RSA-SHA256")
      .update(unsigned)
      .sign(credentials.private_key, "base64url");
    const response = await this.http("https://oauth2.googleapis.com/token", {
      method: "POST",
      signal: AbortSignal.timeout(10000),
      body: new URLSearchParams({
        grant_type: "urn:ietf:params:oauth:grant-type:jwt-bearer",
        assertion: `${unsigned}.${signature}`,
      }),
    });
    if (!response.ok) throw new PushSendError(`OAUTH_${response.status}`);
    const data = (await response.json()) as {
      access_token: string;
      expires_in: number;
    };
    if (!data.access_token) throw new PushSendError("OAUTH_MISSING_TOKEN");
    this.accessToken = data.access_token;
    this.expiresAt = Date.now() + (data.expires_in - 120) * 1000;
    return this.accessToken;
  }

  private async authorize(credentials: FcmCredentials): Promise<string> {
    if (Date.now() < this.expiresAt) return this.accessToken;
    if (this.authorization) return this.authorization;
    const authorization = this.requestAccessToken(credentials);
    this.authorization = authorization;
    try {
      return await authorization;
    } finally {
      if (this.authorization === authorization) this.authorization = undefined;
    }
  }

  async send(
    token: string,
    message: PushMessage,
    options: PushDeliveryOptions = {},
    validateOnly = false,
  ) {
    const credentials = await this.loadCredentials();
    const access = await this.authorize(credentials);
    const showExternal = options.showExternal !== false;
    const response = await this.http(
      `https://fcm.googleapis.com/v1/projects/${encodeURIComponent(credentials.project_id)}/messages:send`,
      {
        method: "POST",
        signal: AbortSignal.timeout(10000),
        headers: {
          Authorization: `Bearer ${access}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          validate_only: validateOnly,
          message: {
            token,
            ...(showExternal
              ? { notification: { title: message.title, body: message.body } }
              : {}),
            data: {
              notification_id: message.id,
              type: message.type,
              title: message.title,
              body: message.body,
              ...(message.linkTitle ? { link_title: message.linkTitle } : {}),
              ...(message.linkUrl ? { link_url: message.linkUrl } : {}),
            },
            android: {
              priority: "high",
              ttl: "3600s",
              ...(showExternal
                ? {
                    notification: {
                      channel_id: "subscriber_alerts",
                      tag: `subscriber-${message.id}`,
                      sound: "default",
                      icon: "ic_stat_notification",
                      color: "#1264DB",
                    },
                  }
                : {}),
            },
          },
        }),
      },
    );
    if (response.ok) return;
    if (response.status === 401) this.expiresAt = 0;
    const error = (await response.json()) as {
      error?: { details?: Array<{ errorCode?: string }> };
    };
    const code =
      error.error?.details?.find((detail) => detail.errorCode)?.errorCode ??
      `FCM_${response.status}`;
    throw new PushSendError(
      code,
      ["UNREGISTERED", "INVALID_ARGUMENT", "SENDER_ID_MISMATCH"].includes(code),
      code === "UNREGISTERED",
    );
  }
}
