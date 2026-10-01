-- Extensions and themes that were installed from a release of their own
-- repository, rather than shipped with the core or uploaded by hand.
-- The row remembers where a package came from, so it can be updated from
-- there and from nowhere else.
CREATE TABLE package (
    kind ENUM('extension', 'theme') NOT NULL,
    id VARCHAR(64) NOT NULL,
    repo VARCHAR(140) NOT NULL,
    version VARCHAR(32) NOT NULL,
    installed_at DATETIME NOT NULL,
    PRIMARY KEY (kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
