<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The cookie behind "Stay logged in". What it means is LoginTokens'
 * business; this class only knows how it is sent.
 */
final class RememberCookie
{
    public const NAME = 'remember';

    /** @var array{value: string, options: array<string, mixed>}|null */
    private static ?array $lastSent = null;

    /** Whether the browser sent the cookie at all - whatever is in it. */
    public static function present(): bool
    {
        return isset($_COOKIE[self::NAME]);
    }

    /** Null for anything but text: "remember[]=x" arrives as an array. */
    public static function read(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function set(string $value, int $expires): void
    {
        self::send($value, $expires);
        // The rest of this request sees what the browser will send next.
        $_COOKIE[self::NAME] = $value;
    }

    public static function clear(): void
    {
        self::send('', time() - 42000);
        unset($_COOKIE[self::NAME]);
    }

    /**
     * What was sent last. The command line has no response to put a cookie
     * in, so this is how the checks see it.
     *
     * @return array{value: string, options: array<string, mixed>}|null
     */
    public static function lastSent(): ?array
    {
        return self::$lastSent;
    }

    private static function send(string $value, int $expires): void
    {
        // The same attributes as the session cookie, see Session::start().
        $options = [
            'expires' => $expires,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        self::$lastSent = ['value' => $value, 'options' => $options];

        if (!headers_sent()) {
            setcookie(self::NAME, $value, $options);
        }
    }
}
