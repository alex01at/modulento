<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

final class PasswordPolicy
{
    // Length is what makes a password hard to guess; composition rules
    // (digit, symbol ...) mostly produce "Password1!" and are left out.
    public const MIN_LENGTH = 12;
    // Far beyond any real password; only keeps absurd input away from the
    // hash function.
    private const MAX_LENGTH = 1000;

    /** @return string|null language key of the problem, null if the password is acceptable */
    public static function problem(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'core.password.too_short';
        }
        if (strlen($password) > self::MAX_LENGTH) {
            return 'core.password.too_long';
        }

        return null;
    }
}
