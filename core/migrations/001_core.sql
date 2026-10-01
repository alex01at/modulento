-- Core schema, stage 1: accounts with roles, extension state, scheduler.
-- A statement ends with ";" at the end of a line (see Migrator::statements).

CREATE TABLE account (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    locale CHAR(2) NOT NULL DEFAULT 'de',
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_account_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_role_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permission (
    role_id INT UNSIGNED NOT NULL,
    permission VARCHAR(128) NOT NULL,
    PRIMARY KEY (role_id, permission),
    CONSTRAINT fk_role_permission_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE account_role (
    account_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (account_id, role_id),
    CONSTRAINT fk_account_role_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_account_role_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "*" grants every permission, including those of extensions enabled later.
INSERT INTO role (name) VALUES ('admin');
INSERT INTO role_permission (role_id, permission) SELECT id, '*' FROM role WHERE name = 'admin';

CREATE TABLE extension (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    version VARCHAR(32) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    enabled_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_run (
    name VARCHAR(128) NOT NULL PRIMARY KEY,
    last_started_at DATETIME NOT NULL,
    last_finished_at DATETIME NULL,
    last_status ENUM('running', 'ok', 'failed') NOT NULL,
    last_error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limit_attempt (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(64) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_rate_limit_lookup (action, identifier, created_at),
    KEY idx_rate_limit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
