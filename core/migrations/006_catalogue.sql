-- The catalogue: categories and offers. What an offer consists of beyond
-- title, text, pictures and a starting price is the business of the
-- extension that registered its type, in tables of its own.

CREATE TABLE category (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Two levels: a category either has no parent or a parent without one.
    parent_id INT UNSIGNED NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_category_parent (parent_id, position),
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE category_translation (
    category_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    PRIMARY KEY (category_id, locale),
    UNIQUE KEY uq_category_translation_slug (locale, slug),
    CONSTRAINT fk_category_translation_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An offer is public while it is "published", its provider is approved
-- and the provider's account is active.
CREATE TABLE offer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    type VARCHAR(64) NOT NULL,
    category_id INT UNSIGNED NULL,
    status ENUM('draft', 'pending', 'published', 'rejected', 'paused') NOT NULL DEFAULT 'draft',
    -- What the provider was told when the offer was rejected or taken down.
    status_note TEXT NULL,
    -- Lowest price in minor units (cents), set by the offer type; NULL
    -- where a type has no fixed price (an auction before its first bid).
    price_from INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_offer_listing (status, published_at),
    KEY idx_offer_provider (provider_id),
    KEY idx_offer_category (category_id, status),
    CONSTRAINT fk_offer_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL,
    CONSTRAINT fk_offer_decided_by FOREIGN KEY (decided_by) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE offer_translation (
    offer_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    summary VARCHAR(300) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (offer_id, locale),
    UNIQUE KEY uq_offer_translation_slug (locale, slug),
    CONSTRAINT fk_offer_translation_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The picture files live in var/uploads/offers/<offer id>/; name is the
-- random file name without extension.
CREATE TABLE offer_image (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    name CHAR(32) NOT NULL,
    extension VARCHAR(5) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_offer_image_offer (offer_id, position),
    CONSTRAINT fk_offer_image_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
