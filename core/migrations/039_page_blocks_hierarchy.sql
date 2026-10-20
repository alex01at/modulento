-- Pages get the same block layout as the home page (hero/categories/text/
-- offers/providers/image/links, see core/src/Content/HomeLayout.php) instead
-- of a single body text, and a parent/child hierarchy for later navigation
-- use. The existing "body" column is left in place - Pages::blocksFor()
-- synthesizes one text block from it lazily, per page, the first time it is
-- opened in the new editor, so no page loses its content.

ALTER TABLE page ADD COLUMN parent_id INT UNSIGNED NULL AFTER role;
ALTER TABLE page ADD COLUMN blocks MEDIUMTEXT NULL AFTER position;
ALTER TABLE page ADD CONSTRAINT fk_page_parent FOREIGN KEY (parent_id) REFERENCES page (id) ON DELETE SET NULL;
ALTER TABLE page ADD INDEX idx_page_parent (parent_id);
