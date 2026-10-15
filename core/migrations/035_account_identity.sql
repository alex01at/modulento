-- Links an account to a social login provider's own id for it ("subject").
-- An account may have none (it only ever signed in with a password) or one
-- per provider; removing the account removes the link with it.

CREATE TABLE account_identity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL,
    subject VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_account_identity (provider, subject),
    KEY idx_account_identity_account (account_id),
    CONSTRAINT fk_account_identity_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
