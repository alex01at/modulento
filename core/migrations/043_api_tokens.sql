-- Logins of an app (the mobile API): one row per device that signed in
-- through POST /api/v1/auth/login.
--
-- The app holds "selector.secret" and sends it as a Bearer token. The
-- selector only finds the row. Of the secret, the SHA-256 alone is stored,
-- so a copy of this table logs nobody in. Unlike account_login_token there
-- is no cookie and no rotation: a token is valid until it is revoked or
-- has expired.
CREATE TABLE account_api_token (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    -- What the app called the device, only shown to the owner.
    name VARCHAR(100) NULL,
    -- Fingerprint of the password the app logged in with, as in the
    -- session: a token from before a password change is worth nothing.
    auth_stamp VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL,
    -- NULL: no end date, valid until revoked.
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_account_api_token_selector (selector),
    KEY idx_account_api_token_account (account_id),
    CONSTRAINT fk_account_api_token_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
