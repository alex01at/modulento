-- A declaration that could not be assigned to an order has to be passed on
-- by an administrator. These columns record that someone has done so.
ALTER TABLE withdrawal ADD COLUMN handled_at DATETIME NULL;
ALTER TABLE withdrawal ADD COLUMN handled_by INT UNSIGNED NULL;
