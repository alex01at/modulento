-- The word filter no longer refuses a message, only flags it - the message
-- is always delivered, and an administrator decides what happens
-- (Controller\AdminMessageController). flagged_word is the word
-- BadWords::find() matched when the message was written, kept so the
-- administration can show why something was flagged; NULL means nothing
-- matched. "Pending" is flagged_word IS NOT NULL AND decided_at IS NULL.
ALTER TABLE order_message
    ADD COLUMN flagged_word VARCHAR(40) NULL,
    ADD COLUMN status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',
    ADD COLUMN decided_at DATETIME NULL,
    ADD COLUMN decided_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_order_message_decided_by FOREIGN KEY (decided_by) REFERENCES account (id) ON DELETE SET NULL;

ALTER TABLE offer_message
    ADD COLUMN flagged_word VARCHAR(40) NULL,
    ADD COLUMN status ENUM('visible', 'hidden') NOT NULL DEFAULT 'visible',
    ADD COLUMN decided_at DATETIME NULL,
    ADD COLUMN decided_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_offer_message_decided_by FOREIGN KEY (decided_by) REFERENCES account (id) ON DELETE SET NULL;
