-- Settings changed in the administration, e.g. the active theme.
CREATE TABLE setting (
    name VARCHAR(128) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
