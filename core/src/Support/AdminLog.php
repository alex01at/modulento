<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use PDO;

/** The record of what administrators did to accounts. */
final class AdminLog
{
    public function __construct(private PDO $db)
    {
    }

    public function record(?int $actor, string $action, ?int $target, string $detail = ''): void
    {
        $this->db->prepare(
            'INSERT INTO admin_log (created_at, actor_id, action, target_id, detail) VALUES (:now, :actor, :action, :target, :detail)'
        )->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'detail' => mb_substr($detail, 0, 255),
        ]);
    }

    /** The newest entries about one account, newest first. @return list<array<string, mixed>> */
    public function forTarget(int $target, int $limit = 20): array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_log WHERE target_id = :target ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $stmt->execute(['target' => $target]);

        return $stmt->fetchAll();
    }
}
