-- Self-service accounts: display name, e-mail confirmation, one-time tokens.
ALTER TABLE account
    ADD COLUMN display_name VARCHAR(100) NULL AFTER email,
    ADD COLUMN email_verified_at DATETIME NULL AFTER status;

-- Accounts that exist already were created by the installer or by
-- bin/create-admin.php and count as confirmed.
UPDATE account SET email_verified_at = created_at;

-- Only the SHA-256 of a token is stored; the token itself exists in the
-- e-mail alone, so a copy of this table cannot be used to take over
-- accounts.
CREATE TABLE account_token (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    payload VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_account_token_hash (token_hash),
    KEY idx_account_token_account (account_id, purpose),
    KEY idx_account_token_expires (expires_at),
    CONSTRAINT fk_account_token_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
