CREATE TABLE custom_field (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity ENUM('offer', 'provider') NOT NULL,
    field_key VARCHAR(60) NOT NULL,
    type ENUM('text', 'textarea', 'number', 'select', 'checkbox', 'date') NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    -- Offer types this field is restricted to; NULL/empty means every type. Unused for entity = 'provider'.
    type_ids JSON NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_custom_field_key (entity, field_key),
    KEY idx_custom_field_entity (entity, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE custom_field_translation (
    field_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    label VARCHAR(150) NOT NULL,
    -- Only for type = 'select': option key => label.
    option_labels JSON NULL,
    PRIMARY KEY (field_id, locale),
    CONSTRAINT fk_custom_field_translation_field FOREIGN KEY (field_id) REFERENCES custom_field (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE custom_field_offer_value (
    field_id INT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NOT NULL,
    -- '' for a locale-independent field (every type except text/textarea).
    locale CHAR(2) NOT NULL DEFAULT '',
    value TEXT NULL,
    PRIMARY KEY (field_id, offer_id, locale),
    CONSTRAINT fk_cfov_field FOREIGN KEY (field_id) REFERENCES custom_field (id) ON DELETE CASCADE,
    CONSTRAINT fk_cfov_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE custom_field_provider_value (
    field_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT '',
    value TEXT NULL,
    PRIMARY KEY (field_id, provider_id, locale),
    CONSTRAINT fk_cfpv_field FOREIGN KEY (field_id) REFERENCES custom_field (id) ON DELETE CASCADE,
    CONSTRAINT fk_cfpv_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
