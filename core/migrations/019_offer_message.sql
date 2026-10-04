-- Messages about an offer, before anyone orders: a thread between one visitor
-- (the asker) and the provider. Every message is a row; asker_id groups the
-- messages of one thread, author_id says who wrote a message.
CREATE TABLE offer_message (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    asker_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_offer_message_thread (offer_id, asker_id, id),
    CONSTRAINT fk_offer_message_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_message_asker FOREIGN KEY (asker_id) REFERENCES account (id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_message_author FOREIGN KEY (author_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
