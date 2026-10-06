-- How a subscription is paid. A Stripe subscription keeps its id here, so that its
-- renewals and its end can be matched to the account. Transfer orders are the
-- ones an operator confirms by hand once the money has arrived.
ALTER TABLE subscription
    ADD COLUMN provider_ref VARCHAR(255) NULL,
    ADD KEY idx_subscription_provider_ref (provider_ref);

CREATE TABLE subscription_order (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    method ENUM('transfer', 'stripe') NOT NULL,
    status ENUM('pending', 'paid', 'canceled') NOT NULL,
    reference VARCHAR(20) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    stripe_session VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_subscription_order_reference (reference),
    KEY idx_subscription_order_account (account_id),
    CONSTRAINT fk_subscription_order_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_order_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
