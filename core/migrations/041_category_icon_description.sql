-- A category can carry a short icon (an emoji or a short keyword a theme
-- interprets on its own - deliberately plain text, not a media-library
-- picture: the point is that someone can type it straight into a CSV cell
-- when importing categories in bulk, before any file has ever been
-- uploaded) and a description per language, next to its existing name.

ALTER TABLE category ADD COLUMN icon VARCHAR(50) NULL AFTER position;
ALTER TABLE category_translation ADD COLUMN description VARCHAR(500) NOT NULL DEFAULT '' AFTER name;
