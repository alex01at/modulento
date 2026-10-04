<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use PDO;
use PDOException;

/**
 * What an account has chosen for itself beyond its profile, as name and
 * value. Kept apart from the account table, which is read on every request
 * and must not depend on anything stored here.
 */
final class Preferences
{
    public const COLOR_SCHEME = 'color_scheme';
    /** "auto" follows the device and is the default. */
    public const COLOR_SCHEMES = ['auto', 'light', 'dark'];
    public const ADMIN_LAYOUT = 'admin_layout';
    /** "sidebar" is the default; "header" puts the menu in a bar on top, with a mega menu per section. */
    public const ADMIN_LAYOUTS = ['sidebar', 'header'];

    public function __construct(private PDO $db)
    {
    }

    public function get(int $accountId, string $name): ?string
    {
        try {
            $stmt = $this->db->prepare('SELECT value FROM account_preference WHERE account_id = :id AND name = :name');
            $stmt->execute(['id' => $accountId, 'name' => $name]);
        } catch (PDOException) {
            // Files of a newer version, database of the older one: between
            // an update and its migrations every preference has its default.
            return null;
        }
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    /** @param string|null $value null forgets the preference, so its default applies again */
    public function set(int $accountId, string $name, ?string $value): void
    {
        $key = ['id' => $accountId, 'name' => $name];
        if ($value === null) {
            $this->db->prepare('DELETE FROM account_preference WHERE account_id = :id AND name = :name')->execute($key);

            return;
        }

        $update = $this->db->prepare('UPDATE account_preference SET value = :value WHERE account_id = :id AND name = :name');
        if ($this->get($accountId, $name) !== null) {
            $update->execute($key + ['value' => $value]);

            return;
        }
        try {
            $this->db->prepare('INSERT INTO account_preference (account_id, name, value) VALUES (:id, :name, :value)')->execute($key + ['value' => $value]);
        } catch (PDOException) {
            // The same form sent twice at once: the other request was first.
            $update->execute($key + ['value' => $value]);
        }
    }

    /** All preferences of an account, for its data export. @return array<string, string> */
    public function all(int $accountId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT name, value FROM account_preference WHERE account_id = :id ORDER BY name');
            $stmt->execute(['id' => $accountId]);
        } catch (PDOException) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** "auto", "light" or "dark"; a visitor and an account without a choice get "auto". */
    public function colorScheme(?int $accountId): string
    {
        $value = $accountId !== null ? $this->get($accountId, self::COLOR_SCHEME) : null;

        return in_array($value, self::COLOR_SCHEMES, true) ? $value : 'auto';
    }

    /** "sidebar" or "header"; an account without a choice gets "sidebar". Only administrators are asked, see View. */
    public function adminLayout(?int $accountId): string
    {
        $value = $accountId !== null ? $this->get($accountId, self::ADMIN_LAYOUT) : null;

        return in_array($value, self::ADMIN_LAYOUTS, true) ? $value : 'sidebar';
    }
}
