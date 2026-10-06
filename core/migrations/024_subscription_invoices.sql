-- Invoices for subscriptions. The numbers run without gaps per year; an invoice keeps
-- the seller's and the buyer's details as they were when it was issued, and it stays
-- when the account is deleted (account_id is then cleared).
CREATE TABLE subscription_billing_address (
    account_id INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    street VARCHAR(200) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    city VARCHAR(100) NOT NULL,
    country CHAR(2) NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_billing_address_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscription_invoice (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(20) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    source_ref VARCHAR(80) NOT NULL,
    account_id INT UNSIGNED NULL,
    plan_name VARCHAR(100) NOT NULL,
    months TINYINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    net_cents INT UNSIGNED NOT NULL,
    tax_rate TINYINT UNSIGNED NOT NULL,
    tax_cents INT UNSIGNED NOT NULL,
    gross_cents INT UNSIGNED NOT NULL,
    buyer_name VARCHAR(150) NOT NULL,
    buyer_street VARCHAR(200) NOT NULL,
    buyer_postal_code VARCHAR(20) NOT NULL,
    buyer_city VARCHAR(100) NOT NULL,
    buyer_country CHAR(2) NOT NULL,
    issuer TEXT NOT NULL,
    issued_at DATETIME NOT NULL,
    UNIQUE KEY uq_subscription_invoice_number (number),
    UNIQUE KEY uq_subscription_invoice_seq (year, seq),
    UNIQUE KEY uq_subscription_invoice_source (source_ref),
    KEY idx_subscription_invoice_account (account_id),
    CONSTRAINT fk_subscription_invoice_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The end of the period a reminder was sent for: one reminder per period.
ALTER TABLE subscription ADD COLUMN reminded_for DATETIME NULL;
