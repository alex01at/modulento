<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\Clock;
use PDO;

/** Links an account to a social login provider's own id for it ("subject"), see migration 035. */
final class AccountIdentities
{
    public function __construct(private PDO $db)
    {
    }

    public function findAccountId(string $provider, string $subject): ?int
    {
        $stmt = $this->db->prepare('SELECT account_id FROM account_identity WHERE provider = :provider AND subject = :subject');
        $stmt->execute(['provider' => $provider, 'subject' => $subject]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function link(int $accountId, string $provider, string $subject): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO account_identity (account_id, provider, subject, created_at) VALUES (:account_id, :provider, :subject, :now)'
        );
        $stmt->execute(['account_id' => $accountId, 'provider' => $provider, 'subject' => $subject, 'now' => Clock::now()]);
    }
}
