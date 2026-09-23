import { createSign } from "node:crypto";
import { readFile } from "node:fs/promises";
export class PushSendError extends Error {
    code;
    permanent;
    invalidToken;
    constructor(code, permanent = false, invalidToken = false) {
        super(code);
        this.code = code;
        this.permanent = permanent;
        this.invalidToken = invalidToken;
    }
}
export class FcmSender {
    credentialsPath;
    http;
    accessToken = "";
    expiresAt = 0;
    credentials;
    authorization;
    constructor(credentialsPath, http = fetch) {
        this.credentialsPath = credentialsPath;
        this.http = http;
    }
    async loadCredentials() {
        if (this.credentials)
            return this.credentials;
        this.credentials = JSON.parse(await readFile(this.credentialsPath, "utf8"));
        return this.credentials;
    }
    async requestAccessToken(credentials) {
        if (Date.now() < this.expiresAt)
            return this.accessToken;
        const now = Math.floor(Date.now() / 1000);
        const encode = (value) => Buffer.from(JSON.stringify(value)).toString("base64url");
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
        if (!response.ok)
            throw new PushSendError(`OAUTH_${response.status}`);
        const data = (await response.json());
        if (!data.access_token)
            throw new PushSendError("OAUTH_MISSING_TOKEN");
        this.accessToken = data.access_token;
        this.expiresAt = Date.now() + (data.expires_in - 120) * 1000;
        return this.accessToken;
    }
    async authorize(credentials) {
        if (Date.now() < this.expiresAt)
            return this.accessToken;
        if (this.authorization)
            return this.authorization;
        const authorization = this.requestAccessToken(credentials);
        this.authorization = authorization;
        try {
            return await authorization;
        }
        finally {
            if (this.authorization === authorization)
                this.authorization = undefined;
        }
    }
    async send(token, message, validateOnly = false) {
        const credentials = await this.loadCredentials();
        const access = await this.authorize(credentials);
        const response = await this.http(`https://fcm.googleapis.com/v1/projects/${encodeURIComponent(credentials.project_id)}/messages:send`, {
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
                    notification: { title: message.title, body: message.body },
                    data: {
                        notification_id: message.id,
                        type: message.type,
                        ...(message.linkTitle ? { link_title: message.linkTitle } : {}),
                        ...(message.linkUrl ? { link_url: message.linkUrl } : {}),
                    },
                    android: {
                        priority: "high",
                        ttl: "3600s",
                        notification: {
                            channel_id: "subscriber_alerts",
                            tag: `subscriber-${message.id}`,
                            sound: "default",
                            icon: "ic_stat_notification",
                            color: "#1264DB",
                        },
                    },
                },
            }),
        });
        if (response.ok)
            return;
        if (response.status === 401)
            this.expiresAt = 0;
        const error = (await response.json());
        const code = error.error?.details?.find((detail) => detail.errorCode)?.errorCode ??
            `FCM_${response.status}`;
        throw new PushSendError(code, ["UNREGISTERED", "INVALID_ARGUMENT", "SENDER_ID_MISMATCH"].includes(code), code === "UNREGISTERED");
    }
}
//# sourceMappingURL=fcm-sender.js.map