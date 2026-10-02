-- What an auction lot has beyond the core's offer, and the bids on it.
--
-- A lot waits ("pending") until its offer is published, then runs ("open")
-- for the chosen number of days and ends as "sold", "unsold" or
-- "cancelled". next_min is the lowest amount the next bid must reach; a bid
-- is accepted by one UPDATE that only succeeds while that is still true.

CREATE TABLE x_auction_lot (
    offer_id INT UNSIGNED PRIMARY KEY,
    start_price INT UNSIGNED NOT NULL,
    step INT UNSIGNED NOT NULL,
    duration_days TINYINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    next_min INT UNSIGNED NOT NULL,
    current_price INT UNSIGNED NULL,
    bid_count INT UNSIGNED NOT NULL DEFAULT 0,
    ends_at DATETIME NULL,
    closed_at DATETIME NULL,
    order_id INT UNSIGNED NULL,
    KEY idx_x_auction_lot_due (status, ends_at),
    CONSTRAINT fk_x_auction_lot_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_auction_lot_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_auction_bid (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    amount INT UNSIGNED NOT NULL,
    terms_accepted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_x_auction_bid_offer (offer_id, amount),
    KEY idx_x_auction_bid_account (account_id),
    CONSTRAINT fk_x_auction_bid_lot FOREIGN KEY (offer_id) REFERENCES x_auction_lot (offer_id) ON DELETE CASCADE,
    CONSTRAINT fk_x_auction_bid_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
