<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

final class AccountLoggedIn
{
    public function __construct(public readonly int $accountId)
    {
    }
}
