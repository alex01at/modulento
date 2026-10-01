<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use PDO;

/**
 * Roles bundle permissions; accounts get roles. The role "admin" holds the
 * wildcard permission and can be neither edited nor deleted, so there is
 * always a role that may do everything.
 */
final class Roles
{
    public const ADMIN = 'admin';
    public const WILDCARD = '*';

    public function __construct(private PDO $db)
    {
    }

    /** @return array<int, array{id: int, name: string, permissions: string[], accounts: int}> */
    public function all(): array
    {
        $roles = [];
        foreach ($this->db->query('SELECT id, name FROM role ORDER BY name')->fetchAll() as $row) {
            $roles[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => $row['name'], 'permissions' => [], 'accounts' => 0];
        }
        foreach ($this->db->query('SELECT role_id, permission FROM role_permission ORDER BY permission')->fetchAll() as $row) {
            if (isset($roles[(int) $row['role_id']])) {
                $roles[(int) $row['role_id']]['permissions'][] = $row['permission'];
            }
        }
        foreach ($this->db->query('SELECT role_id, COUNT(*) AS n FROM account_role GROUP BY role_id')->fetchAll() as $row) {
            if (isset($roles[(int) $row['role_id']])) {
                $roles[(int) $row['role_id']]['accounts'] = (int) $row['n'];
            }
        }

        return $roles;
    }

    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @param string[] $permissions only names from $known are stored
     * @param string[] $known every permission the core and the enabled extensions registered
     * @return string|null language key of the problem, null on success
     */
    public function save(?int $id, string $name, array $permissions, array $known): ?string
    {
        $name = trim($name);
        if (preg_match('/^[\p{L}\p{N} _\-]{2,64}$/u', $name) !== 1) {
            return 'core.admin.roles.error.name';
        }

        $existing = $id !== null ? $this->find($id) : null;
        if (($id !== null && $existing === null) || ($existing['name'] ?? null) === self::ADMIN || strtolower($name) === self::ADMIN) {
            return 'core.admin.roles.error.protected';
        }
        foreach ($this->all() as $role) {
            if (strtolower($role['name']) === strtolower($name) && $role['id'] !== $id) {
                return 'core.admin.roles.error.name_taken';
            }
        }

        $this->db->beginTransaction();

        if ($id === null) {
            $stmt = $this->db->prepare('INSERT INTO role (name) VALUES (:name)');
            $stmt->execute(['name' => $name]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare('UPDATE role SET name = :name WHERE id = :id');
            $stmt->execute(['name' => $name, 'id' => $id]);
        }

        $delete = $this->db->prepare('DELETE FROM role_permission WHERE role_id = :id');
        $delete->execute(['id' => $id]);
        $insert = $this->db->prepare('INSERT INTO role_permission (role_id, permission) VALUES (:id, :permission)');
        // The wildcard is never in $known, so no form can hand it out.
        foreach (array_unique(array_intersect($permissions, $known)) as $permission) {
            $insert->execute(['id' => $id, 'permission' => $permission]);
        }

        $this->db->commit();

        return null;
    }

    public function delete(int $id): bool
    {
        $role = $this->find($id);
        if ($role === null || $role['name'] === self::ADMIN) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM role WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return true;
    }

    /** @return int[] */
    public function idsOfAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT role_id FROM account_role WHERE account_id = :id');
        $stmt->execute(['id' => $accountId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param int[] $roleIds */
    public function setForAccount(int $accountId, array $roleIds): void
    {
        $valid = array_keys($this->all());

        $this->db->beginTransaction();
        $delete = $this->db->prepare('DELETE FROM account_role WHERE account_id = :id');
        $delete->execute(['id' => $accountId]);
        $insert = $this->db->prepare('INSERT INTO account_role (account_id, role_id) VALUES (:account, :role)');
        foreach (array_unique(array_intersect(array_map('intval', $roleIds), $valid)) as $roleId) {
            $insert->execute(['account' => $accountId, 'role' => $roleId]);
        }
        $this->db->commit();
    }

    /** The id of the role that may do everything. */
    public function adminRoleId(): ?int
    {
        foreach ($this->all() as $role) {
            if (in_array(self::WILDCARD, $role['permissions'], true)) {
                return $role['id'];
            }
        }

        return null;
    }
}
