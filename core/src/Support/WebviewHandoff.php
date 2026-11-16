<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use RuntimeException;

/**
 * Lets an app that is logged in with its token open a page of this site in
 * an embedded browser, logged in as the same account: the app asks for a
 * code and opens /webview/session?code=... with it, which starts an
 * ordinary session.
 *
 * The code is "payload.signature": the account, the moment it expires and
 * optionally the page to open, signed with a key derived from this
 * installation's key file. Nothing is stored - whoever checks the code
 * needs only the key. It is valid for a minute, long enough to open one
 * page and short enough to be worth nothing when it shows up in a log.
 */
final class WebviewHandoff
{
    public const TTL_SECONDS = 60;

    private const PURPOSE = 'webview-handoff';

    public function __construct(private Secrets $secrets)
    {
    }

    /**
     * @param string|null $path a path of this site to open afterwards; anything else is left out
     * @throws RuntimeException if this installation's key file cannot be created or read
     */
    public function issue(int $accountId, ?string $path = null, ?int $now = null): string
    {
        $payload = self::encode((string) json_encode([
            'a' => $accountId,
            'e' => ($now ?? time()) + self::TTL_SECONDS,
            'p' => self::localPath($path),
        ]));

        return $payload . '.' . $this->secrets->mac(self::PURPOSE, $payload);
    }

    /** @return array{account_id: int, path: ?string}|null null for a code that was changed, has expired or is none */
    public function verify(mixed $code, ?int $now = null): ?array
    {
        if (!is_string($code) || preg_match('/\A([A-Za-z0-9_-]{1,2048})\.([a-f0-9]{64})\z/', $code, $m) !== 1) {
            return null;
        }

        try {
            $expected = $this->secrets->mac(self::PURPOSE, $m[1]);
        } catch (RuntimeException) {
            return null;
        }
        if (!hash_equals($expected, $m[2])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        if (!is_array($data) || !is_int($data['a'] ?? null) || !is_int($data['e'] ?? null) || $data['e'] <= ($now ?? time())) {
            return null;
        }

        return ['account_id' => $data['a'], 'path' => self::localPath($data['p'] ?? null)];
    }

    /** A path of this site, or null: never a full or protocol-relative address. */
    public static function localPath(mixed $path): ?string
    {
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20]/', $path) === 1) {
            return null;
        }

        return $path;
    }

    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
