-- "Stay logged in": one row per device that ticked the box at login.
--
-- The cookie holds "selector:secret". The selector only finds the row. Of
-- the secret, the SHA-256 alone is stored, so a copy of this table logs
-- nobody in.
CREATE TABLE account_login_token (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    -- The secret that was valid before the last rotation. Accepted for a
    -- few seconds after rotated_at, for requests the browser sent at the
    -- same moment with the cookie it had then.
    previous_hash CHAR(64) NULL,
    rotated_at DATETIME NULL,
    -- Fingerprint of the password the device logged in with, as in the
    -- session: a token from before a password change is worth nothing.
    auth_stamp CHAR(32) NOT NULL,
    -- Shortened, and only shown to the owner to tell devices apart.
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_account_login_token_selector (selector),
    KEY idx_account_login_token_account (account_id),
    KEY idx_account_login_token_expires (expires_at),
    CONSTRAINT fk_account_login_token_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
