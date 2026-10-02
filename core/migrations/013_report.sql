-- Notices about content someone considers illegal, sent through the report
-- form, and what the platform decided about each.
--
-- A notice outlives the account that sent it (the reference becomes NULL);
-- name and address stay as typed, since the decision has to reach them.
CREATE TABLE report (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Where the content is, exactly as it was entered.
    url VARCHAR(500) NOT NULL,
    category VARCHAR(32) NOT NULL,
    explanation TEXT NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    -- The language the form was used in; the decision is sent in it.
    locale CHAR(2) NOT NULL,
    account_id INT UNSIGNED NULL,
    -- open, actioned (something was done about the content) or rejected.
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    -- The reasons given to the sender with the decision.
    decision_note TEXT NULL,
    decided_at DATETIME NULL,
    decided_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_report_status (status, id),
    KEY idx_report_account (account_id, id),
    CONSTRAINT fk_report_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
