-- Marks a row as demo data (Administration → Demo data), so it can be
-- shown as such and removed again in one step. Demo accounts own every
-- other piece of demo data (their provider profile, their requests) and
-- carry the only foreign key that matters: deleting the demo accounts
-- cascades to their provider and offers, and to their requests.

ALTER TABLE account ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE provider ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE offer ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE request ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
