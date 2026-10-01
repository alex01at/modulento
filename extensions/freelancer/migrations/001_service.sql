-- What a freelancer service consists of beyond the core's offer: up to
-- three packages, optional extras, and what the provider needs from the
-- buyer. Everything a buyer reads has one text per language.

CREATE TABLE x_freelancer_package (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    price INT UNSIGNED NOT NULL,
    delivery_days SMALLINT UNSIGNED NOT NULL,
    revisions TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_x_freelancer_package (offer_id, tier),
    CONSTRAINT fk_x_freelancer_package_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_package_translation (
    package_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(1000) NOT NULL,
    PRIMARY KEY (package_id, locale),
    CONSTRAINT fk_x_freelancer_package_translation FOREIGN KEY (package_id) REFERENCES x_freelancer_package (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_extra (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    price INT UNSIGNED NOT NULL,
    extra_days SMALLINT UNSIGNED NOT NULL,
    KEY idx_x_freelancer_extra_offer (offer_id, position),
    CONSTRAINT fk_x_freelancer_extra_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_extra_translation (
    extra_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    title VARCHAR(150) NOT NULL,
    PRIMARY KEY (extra_id, locale),
    CONSTRAINT fk_x_freelancer_extra_translation FOREIGN KEY (extra_id) REFERENCES x_freelancer_extra (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE x_freelancer_requirement (
    offer_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    text VARCHAR(2000) NOT NULL,
    PRIMARY KEY (offer_id, locale),
    CONSTRAINT fk_x_freelancer_requirement_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
