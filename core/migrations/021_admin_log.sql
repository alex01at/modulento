-- What administrators did to accounts: created them, and signed in as them. The
-- record outlives the account it is about (target_id is cleared when it goes).
CREATE TABLE admin_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    target_id INT UNSIGNED NULL,
    detail VARCHAR(255) NOT NULL DEFAULT '',
    KEY idx_admin_log_target (target_id),
    CONSTRAINT fk_admin_log_actor FOREIGN KEY (actor_id) REFERENCES account (id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_log_target FOREIGN KEY (target_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
