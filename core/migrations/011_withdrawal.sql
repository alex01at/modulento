-- Declarations of withdrawal sent through the withdrawal form. A row is the
-- record of what was declared and when it arrived; it changes no order.
--
-- A declaration outlives the order and the account it refers to (the
-- references become NULL), so everything typed is kept as it was typed.
CREATE TABLE withdrawal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Set only when order number and buyer fit together, see "matched".
    order_id INT UNSIGNED NULL,
    -- The order number exactly as it was entered.
    order_number VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    -- Where the acknowledgement of receipt was sent.
    email VARCHAR(255) NOT NULL,
    statement TEXT NOT NULL,
    -- The language the form was used in.
    locale CHAR(2) NOT NULL,
    -- The account that was logged in, or the buyer of the matched order.
    account_id INT UNSIGNED NULL,
    -- Whether the declaration could be assigned to an order when it arrived.
    -- Stays 1 after that order has been deleted.
    matched TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- When the declaration arrived, UTC.
    created_at DATETIME NOT NULL,
    KEY idx_withdrawal_order (order_id),
    KEY idx_withdrawal_account (account_id, id),
    CONSTRAINT fk_withdrawal_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL,
    CONSTRAINT fk_withdrawal_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
