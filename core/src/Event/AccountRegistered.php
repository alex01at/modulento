<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

final class AccountRegistered
{
    public function __construct(public readonly int $accountId)
    {
    }
}
