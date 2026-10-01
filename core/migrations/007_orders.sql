-- Orders. The core runs every order as a state machine; which states and
-- transitions exist comes from the flow an extension registered for the
-- offer's type.
--
-- An order outlives what it refers to: buyer, provider and offer may be
-- deleted later (the references become NULL), so the names and the title
-- are copied into the order when it is placed.

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT UNSIGNED NULL,
    provider_id INT UNSIGNED NULL,
    offer_id INT UNSIGNED NULL,
    flow VARCHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL,
    -- The state before the current one, for transitions that lead "back".
    previous_state VARCHAR(32) NULL,
    -- Who caused the current state: buyer, provider, admin or system.
    state_actor VARCHAR(16) NOT NULL,
    buyer_name VARCHAR(255) NOT NULL,
    provider_name VARCHAR(150) NOT NULL,
    offer_title VARCHAR(150) NOT NULL,
    total INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    -- The language the buyer ordered in.
    locale CHAR(2) NOT NULL,
    payment_method VARCHAR(64) NOT NULL,
    payment_state ENUM('unpaid', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid',
    paid_at DATETIME NULL,
    -- What the flow needs to remember about this order, as JSON.
    data TEXT NOT NULL,
    -- When due_at passes, the scheduler applies due_transition.
    due_at DATETIME NULL,
    due_transition VARCHAR(64) NULL,
    terms_accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Set when the order reaches a final state.
    closed_at DATETIME NULL,
    KEY idx_orders_buyer (buyer_id, id),
    KEY idx_orders_provider (provider_id, id),
    KEY idx_orders_due (due_at),
    KEY idx_orders_state (state),
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES account (id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Amounts are fixed at the time of ordering, in minor units.
CREATE TABLE order_item (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    KEY idx_order_item_order (order_id, position),
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The history: every transition, who made it and what they wrote.
CREATE TABLE order_event (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    transition VARCHAR(64) NOT NULL,
    from_state VARCHAR(32) NULL,
    to_state VARCHAR(32) NOT NULL,
    actor_id INT UNSIGNED NULL,
    actor_role VARCHAR(16) NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_order_event_order (order_id, id),
    CONSTRAINT fk_order_event_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_event_actor FOREIGN KEY (actor_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_message (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NULL,
    author_role VARCHAR(16) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_order_message_order (order_id, id),
    CONSTRAINT fk_order_message_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_message_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
