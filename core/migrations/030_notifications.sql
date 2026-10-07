-- In-app notifications: a short line plus a link, shown behind the bell icon
-- in the header. Filled at exactly the points that already send an e-mail
-- about the same thing (Order\OrderNotifier, the admin "announce" helpers,
-- Catalogue\OfferController, the auction extension's outbid notice) - see
-- Notification\Notifications::create(). "type" is a short slug for
-- possible future per-type handling; message_key/params are rendered with
-- trans(), the same way order history entries already are.
CREATE TABLE notification (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    message_key VARCHAR(100) NOT NULL,
    params TEXT NOT NULL,
    link VARCHAR(255) NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_notification_account (account_id, read_at, id),
    CONSTRAINT fk_notification_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
