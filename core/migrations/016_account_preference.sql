-- What an account has chosen for itself, such as the colour scheme. A table
-- of its own, so that loading the logged-in account never depends on it.
CREATE TABLE account_preference (
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    value VARCHAR(190) NOT NULL,
    PRIMARY KEY (account_id, name),
    CONSTRAINT fk_account_preference_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
