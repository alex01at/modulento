<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Timestamps are created here, in PHP and in UTC, and handed to SQL as
 * parameters - not taken from the database's NOW(). One clock for every
 * comparison, and queries that do not depend on one database's functions.
 */
final class Clock
{
    public static function now(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}
