-- A buyer's own company details, for their records and for whoever they
-- deal with - separate from the provider table, which is about someone
-- *offering* something. Entirely optional: a private buyer leaves this empty.
CREATE TABLE account_billing_profile (
    account_id INT UNSIGNED PRIMARY KEY,
    company_name VARCHAR(150) NULL,
    website VARCHAR(255) NULL,
    vat_id VARCHAR(20) NULL,
    tax_id VARCHAR(50) NULL,
    street VARCHAR(200) NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(100) NULL,
    country CHAR(2) NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_account_billing_profile_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
