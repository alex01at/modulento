-- The media library: pictures an administrator uploads once and can use
-- anywhere on the site. The file itself lives in var/uploads/media, under a
-- random name; this table keeps what the administration shows about it.
CREATE TABLE media (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    file VARCHAR(40) NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '',
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_file (file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
