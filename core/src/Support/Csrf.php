<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Synchronizer-token CSRF protection - one token per session (not per
 * form), so it survives Auth::login()'s session regeneration and doesn't
 * break multi-tab usage. Enforced centrally in Router::dispatch().
 */
final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function verify(?string $submitted): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';

        return $submitted !== null && $expected !== '' && hash_equals($expected, $submitted);
    }
}
