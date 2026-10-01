-- Extension tables are named x_<extension id>_<name>.
CREATE TABLE x_example_login (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    logged_in_at DATETIME NOT NULL,
    KEY idx_x_example_login_time (logged_in_at),
    CONSTRAINT fk_x_example_login_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
