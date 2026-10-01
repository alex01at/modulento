-- Files attached to an order: to the order itself (through its first
-- history entry), to a later step such as a delivery, or to a message.
-- The files live in var/uploads/orders/<order id>/ under stored_name, a
-- random name without extension; original_name is only ever used as the
-- name offered for download.
CREATE TABLE order_file (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NULL,
    message_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    author_role VARCHAR(16) NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    stored_name CHAR(32) NOT NULL,
    size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_order_file_order (order_id, id),
    CONSTRAINT fk_order_file_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_file_event FOREIGN KEY (event_id) REFERENCES order_event (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_file_message FOREIGN KEY (message_id) REFERENCES order_message (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_file_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
