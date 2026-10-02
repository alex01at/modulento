-- Payments go from the buyer straight to the provider: by bank transfer, or
-- through the provider's own PayPal or Stripe account. The platform never
-- holds the money; it stores what each provider set up and which payments
-- were started for an order.

-- What a provider set up for a way to pay. "data" is JSON: bank details,
-- the id of the connected Stripe account, or the credentials of the
-- provider's PayPal app - secrets in it are encrypted with a key that is
-- not in the database (var/secret.key).
CREATE TABLE provider_payment (
    provider_id INT UNSIGNED NOT NULL,
    method VARCHAR(64) NOT NULL,
    data TEXT NOT NULL,
    -- "ready" once buyers can be offered the method.
    status VARCHAR(16) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (provider_id, method),
    CONSTRAINT fk_provider_payment_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A payment started at a payment service for an order; one row per attempt.
CREATE TABLE order_payment (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    method VARCHAR(64) NOT NULL,
    -- The service's own id (Stripe Checkout Session, PayPal order). What
    -- the service reports is matched to an order through this, and the
    -- service is only ever asked about ids stored here.
    provider_reference VARCHAR(191) NULL,
    -- pending, paid or failed (the service refused to start it).
    status VARCHAR(16) NOT NULL,
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_order_payment_reference (method, provider_reference),
    KEY idx_order_payment_order (order_id, id),
    CONSTRAINT fk_order_payment_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
