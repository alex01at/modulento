<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

final class AccountDeleted
{
    /** Dispatched after the account row is gone; rows referencing it by foreign key went with it. */
    public function __construct(public readonly int $accountId, public readonly string $email)
    {
    }
}
