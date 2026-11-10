-- A page may show itself as a blog-style teaser on the home page (the new
-- "pages" home block, see HomeLayout.php): in_home is the opt-in flag, the
-- same convention as in_header/in_footer. teaser_image is a page-wide
-- picture (not translated - the same photo in every language, like a
-- blog post's title image); teaser is a short per-language excerpt next
-- to it.

ALTER TABLE page ADD COLUMN in_home TINYINT(1) NOT NULL DEFAULT 0 AFTER in_footer;
ALTER TABLE page ADD COLUMN teaser_image VARCHAR(255) NULL AFTER in_home;
ALTER TABLE page_translation ADD COLUMN teaser VARCHAR(300) NULL AFTER meta_description;
