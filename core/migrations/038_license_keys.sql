-- A license key per product an operator sells under its own license (a
-- "license:<product>" package repo, see Package\Packages::isLicensedRepo()).
-- The key is never stored in plain text - see License\LicenseKeys, which
-- encrypts it with the same Secrets mechanism as a payment service's own
-- secret key.

CREATE TABLE license_key (
    product_id VARCHAR(64) NOT NULL PRIMARY KEY,
    key_encrypted TEXT NOT NULL,
    added_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
