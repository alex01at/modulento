-- An account's proof of identity, checked by an administrator: not "is this
-- e-mail real" (that is core.admin.accounts.verify, the e-mail confirmation),
-- but "is this person who they say they are" - fraud prevention for a
-- marketplace where strangers pay each other. Files are kept outside the
-- web root and handed out only to administrators, never shown publicly;
-- only the resulting status is.
CREATE TABLE account_identity_document (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_account_identity_document_account (account_id),
    CONSTRAINT fk_account_identity_document_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE account
    ADD COLUMN identity_status ENUM('none', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'none' AFTER status_note,
    ADD COLUMN identity_note TEXT NULL AFTER identity_status,
    ADD COLUMN identity_decided_at DATETIME NULL AFTER identity_note,
    ADD COLUMN identity_decided_by INT UNSIGNED NULL AFTER identity_decided_at,
    ADD CONSTRAINT fk_account_identity_decided_by FOREIGN KEY (identity_decided_by) REFERENCES account (id) ON DELETE SET NULL;
