-- Provider profiles: who offers something on the platform, with the
-- details a marketplace has to know and, for businesses, to show.

CREATE TABLE provider (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    type ENUM('business', 'private') NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
    -- What the provider was told when rejected or suspended.
    status_note TEXT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    legal_name VARCHAR(200) NOT NULL,
    street VARCHAR(200) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    city VARCHAR(100) NOT NULL,
    country CHAR(2) NOT NULL,
    contact_email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    vat_id VARCHAR(20) NULL,
    tax_id VARCHAR(50) NULL,
    company_register VARCHAR(100) NULL,
    -- A business confirms that it only offers what complies with the law.
    self_certified_at DATETIME NULL,
    -- Set whenever identity or legal details change; later than
    -- decided_at means the profile changed since it was last checked.
    details_changed_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_provider_account (account_id),
    UNIQUE KEY uq_provider_slug (slug),
    KEY idx_provider_status (status),
    CONSTRAINT fk_provider_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_decided_by FOREIGN KEY (decided_by) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The public self-description, one per language.
CREATE TABLE provider_translation (
    provider_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    headline VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (provider_id, locale),
    CONSTRAINT fk_provider_translation_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Why an account was blocked, as told to its owner.
ALTER TABLE account ADD COLUMN status_note TEXT NULL AFTER status;
