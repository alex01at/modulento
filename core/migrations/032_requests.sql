-- The other direction from the catalogue: a buyer describes what they
-- need, providers apply with their own price, the buyer accepts one and
-- that becomes a real order (orders.offer_id is already nullable for
-- exactly this - see RequestFlow).
CREATE TABLE request (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    slug VARCHAR(170) NOT NULL,
    description TEXT NOT NULL,
    budget_min INT UNSIGNED NULL,
    budget_max INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL,
    needed_by DATE NULL,
    status ENUM('pending', 'published', 'rejected', 'closed', 'fulfilled') NOT NULL DEFAULT 'pending',
    status_note TEXT NULL,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    accepted_application_id INT UNSIGNED NULL,
    order_id INT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_request_slug (slug),
    KEY idx_request_account (account_id, id),
    KEY idx_request_listing (status, published_at),
    KEY idx_request_category (category_id, status),
    CONSTRAINT fk_request_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_request_category FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL,
    CONSTRAINT fk_request_decided_by FOREIGN KEY (decided_by) REFERENCES account (id) ON DELETE SET NULL,
    CONSTRAINT fk_request_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_application (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    price INT UNSIGNED NOT NULL,
    delivery_days INT UNSIGNED NULL,
    message TEXT NOT NULL,
    status ENUM('submitted', 'accepted', 'declined', 'withdrawn') NOT NULL DEFAULT 'submitted',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_request_application (request_id, provider_id),
    KEY idx_application_provider (provider_id, id),
    CONSTRAINT fk_application_request FOREIGN KEY (request_id) REFERENCES request (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE request
    ADD CONSTRAINT fk_request_accepted_application FOREIGN KEY (accepted_application_id) REFERENCES request_application (id) ON DELETE SET NULL;
