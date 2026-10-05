-- Up to which message an account has read a conversation: an order's messages,
-- or one visitor's thread on an offer (sub_id is the visitor; 0 for an order).
-- seen_id is the newest message id at that time; messages from others with a
-- larger id are unread.
CREATE TABLE message_seen (
    account_id INT UNSIGNED NOT NULL,
    scope VARCHAR(16) NOT NULL,
    ref_id INT UNSIGNED NOT NULL,
    sub_id INT UNSIGNED NOT NULL DEFAULT 0,
    seen_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (account_id, scope, ref_id, sub_id),
    CONSTRAINT fk_message_seen_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
