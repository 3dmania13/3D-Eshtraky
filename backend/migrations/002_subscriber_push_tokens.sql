-- FCM registration tokens are application-owned and may be safely removed.
CREATE TABLE IF NOT EXISTS subscriber_push_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    token VARCHAR(512) NOT NULL,
    platform VARCHAR(16) NOT NULL DEFAULT 'android',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_subscriber_push_token (token),
    KEY idx_subscriber_push_tokens_user (username, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
