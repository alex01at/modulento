-- Languages, pages with one text per language, and the proof that the
-- terms were accepted at registration.

-- Installations from before languages were configurable ran in German
-- and English; the installer sets these itself from now on.
INSERT IGNORE INTO setting (name, value) VALUES ('core.languages', 'de,en');
INSERT IGNORE INTO setting (name, value) VALUES ('core.default_language', 'de');

ALTER TABLE account ADD COLUMN terms_accepted_at DATETIME NULL AFTER email_verified_at;

-- role marks the legal pages the core links by itself: imprint, privacy,
-- terms. At most one page per role.
CREATE TABLE page (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    role VARCHAR(32) NULL,
    in_header TINYINT(1) NOT NULL DEFAULT 0,
    in_footer TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_page_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A page exists in a language when it has a row here. The slug is the
-- page's address in that language and unique within it.
CREATE TABLE page_translation (
    page_id INT UNSIGNED NOT NULL,
    locale CHAR(2) NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    meta_description VARCHAR(300) NULL,
    body MEDIUMTEXT NOT NULL,
    PRIMARY KEY (page_id, locale),
    UNIQUE KEY uq_page_translation_slug (locale, slug),
    CONSTRAINT fk_page_translation_page FOREIGN KEY (page_id) REFERENCES page (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
