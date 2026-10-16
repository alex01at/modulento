<?php

declare(strict_types=1);

namespace Modulento\Core\License;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Secrets;
use PDO;

/**
 * The license keys an operator entered for the products they sell under
 * their own license, one per product id ("license:<product>" as a package
 * repo - see Package\Packages::isLicensedRepo()). Encrypted at rest with
 * the same mechanism a payment service's own secret key uses: unreadable
 * from a database dump alone, lost if var/secret.key is lost.
 */
final class LicenseKeys
{
    public function __construct(private PDO $db, private Secrets $secrets)
    {
    }

    /** The key for a product, or null if none is stored or it cannot be decrypted any more. */
    public function find(string $productId): ?string
    {
        $stmt = $this->db->prepare('SELECT key_encrypted FROM license_key WHERE product_id = :id');
        $stmt->execute(['id' => $productId]);
        $stored = $stmt->fetchColumn();

        return $stored !== false ? $this->secrets->decrypt((string) $stored) : null;
    }

    /**
     * Every product id a key is stored for, with the key's last four
     * characters only - enough for an operator to recognise which one is
     * which, never enough to use it.
     *
     * @return array<int, array{product_id: string, masked: string, added_at: string}>
     */
    public function all(): array
    {
        $rows = $this->db->query('SELECT product_id, key_encrypted, added_at FROM license_key ORDER BY product_id')->fetchAll();

        return array_map(function (array $row): array {
            $key = $this->secrets->decrypt((string) $row['key_encrypted']);

            return [
                'product_id' => $row['product_id'],
                'masked' => $key !== null && $key !== '' ? '••••' . substr($key, -4) : '••••',
                'added_at' => $row['added_at'],
            ];
        }, $rows);
    }

    public function save(string $productId, string $key): void
    {
        $encrypted = $this->secrets->encrypt($key);
        $now = Clock::now();

        $delete = $this->db->prepare('DELETE FROM license_key WHERE product_id = :id');
        $delete->execute(['id' => $productId]);
        $insert = $this->db->prepare('INSERT INTO license_key (product_id, key_encrypted, added_at) VALUES (:id, :key, :now)');
        $insert->execute(['id' => $productId, 'key' => $encrypted, 'now' => $now]);
    }

    public function remove(string $productId): void
    {
        $this->db->prepare('DELETE FROM license_key WHERE product_id = :id')->execute(['id' => $productId]);
    }
}
