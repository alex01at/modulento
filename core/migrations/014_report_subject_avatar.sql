-- What a notice is about, where its address leads to an offer or a
-- provider of this site: the administrator gets there with one click, and
-- the provider can be told when action is taken.
ALTER TABLE report ADD COLUMN offer_id INT UNSIGNED NULL;
ALTER TABLE report ADD COLUMN provider_id INT UNSIGNED NULL;

-- The picture an account shows of itself. A table of its own, so that
-- loading the logged-in account never depends on it.
CREATE TABLE account_avatar (
    account_id INT UNSIGNED PRIMARY KEY,
    -- Random file name below var/uploads/avatars, without extension.
    name CHAR(32) NOT NULL,
    extension VARCHAR(4) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_account_avatar_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
