-- A rating of one account by another, outside any specific order: not what
-- a buyer thought of one purchase, but what one account thinks of another
-- as a person or a business to deal with. Today it needs a real order
-- between the two (in either direction) - see AccountRatings::canRate(),
-- which is where that rule lives and can later be replaced or extended by a
-- context that has its own notion of a genuine encounter (a dating
-- extension's "there was a match", for one) without this table changing.
CREATE TABLE account_rating (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rater_id INT UNSIGNED NULL,
    -- The rater's display name when the rating was written; emptied when
    -- the account is deleted. Never an e-mail address.
    rater_name VARCHAR(100) NOT NULL,
    rated_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    -- The language the rating was written in.
    locale CHAR(2) NOT NULL,
    status ENUM('published', 'hidden') NOT NULL DEFAULT 'published',
    -- What the rater was told when an administrator hid the rating.
    status_note TEXT NULL,
    reply TEXT NULL,
    replied_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_account_rating_pair (rater_id, rated_id),
    KEY idx_account_rating_rated (rated_id, status, id),
    CONSTRAINT fk_account_rating_rater FOREIGN KEY (rater_id) REFERENCES account (id) ON DELETE SET NULL,
    CONSTRAINT fk_account_rating_rated FOREIGN KEY (rated_id) REFERENCES account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Number and sum of the published ratings, kept with the account so that a
-- profile can show and sort by them without counting (Reviews::summary()
-- reads the same two columns for an offer or a provider already).
ALTER TABLE account
    ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN rating_sum INT UNSIGNED NOT NULL DEFAULT 0;
