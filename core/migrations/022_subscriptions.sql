-- Plans an operator sells, and which plan an account has now. A plan lists its
-- features as a comma-separated list; an extension asks for one of them by name.
-- The history of an account stays: a changed plan cancels the subscription it replaces.
CREATE TABLE subscription_plan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    period_months TINYINT UNSIGNED NOT NULL,
    features VARCHAR(1000) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_subscription_plan_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscription (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    plan_id INT UNSIGNED NOT NULL,
    status ENUM('trialing', 'active', 'past_due', 'canceled') NOT NULL,
    period_end DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_subscription_account (account_id, status),
    CONSTRAINT fk_subscription_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
