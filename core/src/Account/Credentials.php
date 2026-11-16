<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\App;
use Modulento\Core\Support\ClientIp;
use Modulento\Core\Support\RateLimiter;

/**
 * Checks an e-mail address and a password, with the limits against
 * guessing. Shared by the login form and the mobile API, so both count
 * against the same limits and refuse for the same reasons. What happens
 * after the answer (session, token, redirect) is the caller's business.
 */
final class Credentials
{
    private const MAX_ATTEMPTS = 5;
    private const MAX_ATTEMPTS_PER_ADDRESS = 50;
    private const WINDOW_SECONDS = 900;
    // Verified against when the e-mail is unknown, so a miss costs the
    // same time as a wrong password and does not reveal which e-mails exist.
    private const DUMMY_HASH = '$2y$10$dahvZsR74OJVWmnBzXm8OuCKPsGLcpoXzRJ0HHV1VirT1dpUji32y';

    /**
     * "account" is set when the password was right - also together with
     * the error "core.login.unverified", where the address still has to be
     * confirmed.
     *
     * @return array{ok: bool, account: ?array, error_key: ?string}
     */
    public static function verify(App $app, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $ip = ClientIp::key();

        // The tight limit counts per address and network address together:
        // wrong passwords typed somewhere else must not lock the owner
        // out. Per address alone there is only a wide limit against
        // guessing from many places at once.
        $pair = $email . '|' . $ip;
        $limiter = new RateLimiter($app->db);
        if ($limiter->tooManyAttempts('login', $ip, self::MAX_ATTEMPTS * 4, self::WINDOW_SECONDS)
            || $limiter->tooManyAttempts('login-pair', $pair, self::MAX_ATTEMPTS, self::WINDOW_SECONDS)
            || $limiter->tooManyAttempts('login-email', $email, self::MAX_ATTEMPTS_PER_ADDRESS, 3600)) {
            return ['ok' => false, 'account' => null, 'error_key' => 'core.login.too_many_attempts'];
        }

        // Counted before the password is checked, and taken back if it was
        // right: requests sent at the same moment cannot slip past the limit.
        $limiter->recordAttempt('login', $ip);
        $limiter->recordAttempt('login-pair', $pair);
        $limiter->recordAttempt('login-email', $email);

        $account = $app->accounts->findByEmail($email);

        if (!password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH)
            || $account === null || $account['status'] !== 'active') {
            return ['ok' => false, 'account' => null, 'error_key' => 'core.login.failed'];
        }

        $limiter->release('login', $ip);
        $limiter->release('login-email', $email);
        $limiter->forget('login-pair', $pair);

        // Only reached with the correct password, so saying "not confirmed
        // yet" tells nobody else that the account exists.
        if ($account['email_verified_at'] === null) {
            return ['ok' => false, 'account' => $account, 'error_key' => 'core.login.unverified'];
        }

        return ['ok' => true, 'account' => $account, 'error_key' => null];
    }
}
