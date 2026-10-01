-- Reviews: one per order, written by its buyer once the order ended in a
-- state its flow marks as reviewable. That link to a real order is what
-- makes a review genuine.
CREATE TABLE review (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NULL,
    provider_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NULL,
    -- The author's display name when the review was written; emptied when
    -- the account is deleted. Never an e-mail address.
    author_name VARCHAR(100) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    -- The language the review was written in.
    locale CHAR(2) NOT NULL,
    status ENUM('published', 'hidden') NOT NULL DEFAULT 'published',
    -- What the author was told when an administrator hid the review.
    status_note TEXT NULL,
    reply TEXT NULL,
    replied_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_review_order (order_id),
    KEY idx_review_offer (offer_id, status, id),
    KEY idx_review_provider (provider_id, status, id),
    CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_offer FOREIGN KEY (offer_id) REFERENCES offer (id) ON DELETE SET NULL,
    CONSTRAINT fk_review_provider FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_author FOREIGN KEY (author_id) REFERENCES account (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Number and sum of the published ratings, kept with the offer and the
-- provider so that lists can show and sort by them without counting.
ALTER TABLE offer
    ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN rating_sum INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE provider
    ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN rating_sum INT UNSIGNED NOT NULL DEFAULT 0;
