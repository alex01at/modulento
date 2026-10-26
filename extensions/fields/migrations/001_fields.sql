-- Extension tables are named x_<extension id>_<name>.

-- A kind of offer the operator made ("Hotel", "Event"); its offers have the
-- type "fields.<key_name>".
CREATE TABLE x_fields_type (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(24) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_x_fields_type_key (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_fields_type_label (
    type_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    label VARCHAR(100) NOT NULL,
    PRIMARY KEY (type_id, locale),
    CONSTRAINT fk_x_fields_type_label_type FOREIGN KEY (type_id) REFERENCES x_fields_type (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A field of a type. The kind cannot change once the field exists, so the
-- stored values always fit it. options: for "select", a JSON list of
-- {"value": "...", "labels": {"de": "...", "en": "..."}}.
CREATE TABLE x_fields_field (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_id INT UNSIGNED NOT NULL,
    key_name VARCHAR(24) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    filterable TINYINT(1) NOT NULL DEFAULT 0,
    sortable TINYINT(1) NOT NULL DEFAULT 0,
    in_card TINYINT(1) NOT NULL DEFAULT 0,
    is_price TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    options TEXT NULL,
    UNIQUE KEY uq_x_fields_field_key (type_id, key_name),
    CONSTRAINT fk_x_fields_field_type FOREIGN KEY (type_id) REFERENCES x_fields_type (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_fields_field_label (
    field_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    label VARCHAR(100) NOT NULL,
    PRIMARY KEY (field_id, locale),
    CONSTRAINT fk_x_fields_field_label_field FOREIGN KEY (field_id) REFERENCES x_fields_field (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What an offer has in a field. Texts are kept per language (locale), all
-- other kinds with locale ''. value_num serves numbers and checkboxes,
-- value_text everything else (a date as YYYY-MM-DD, a select as its option's
-- value); the two indexes are what filtering and sorting use.
CREATE TABLE x_fields_value (
    offer_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT '',
    value_text TEXT NULL,
    value_num DECIMAL(20,4) NULL,
    PRIMARY KEY (offer_id, field_id, locale),
    KEY idx_x_fields_value_num (field_id, value_num),
    KEY idx_x_fields_value_text (field_id, value_text(64)),
    CONSTRAINT fk_x_fields_value_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE,
    CONSTRAINT fk_x_fields_value_field FOREIGN KEY (field_id) REFERENCES x_fields_field (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
