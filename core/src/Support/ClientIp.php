<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The address a request really comes from.
 *
 * Behind a reverse proxy or a CDN every request arrives from the proxy, and
 * all visitors would share one rate limit. The proxy names the visitor in
 * X-Forwarded-For - a header anyone can send, so it is believed only when
 * the request comes from an address listed as TRUSTED_PROXIES.
 */
final class ClientIp
{
    /**
     * @param array<string, mixed> $server $_SERVER
     * @param string[] $trustedProxies addresses or networks ("10.0.0.0/8")
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : 'unknown';
        $forwarded = is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? $server['HTTP_X_FORWARDED_FOR'] : '';

        if ($trustedProxies === [] || $forwarded === '' || !self::isTrusted($remote, $trustedProxies)) {
            return $remote;
        }

        // Each proxy appends the address it received the request from. Read
        // from the end, the first one that is not a proxy of ours is the
        // visitor; anything further left is the visitor's own claim.
        foreach (array_reverse(array_map('trim', explode(',', $forwarded))) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!self::isTrusted($candidate, $trustedProxies)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * What limits are counted by. An IPv6 connection usually owns a whole
     * /64 network and could change its address with every request.
     */
    public static function key(): string
    {
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;

        return $packed !== false ? inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64' : $ip;
    }

    /** @param string[] $networks */
    public static function isTrusted(string $ip, array $networks): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($networks as $network) {
            [$base, $bits] = array_pad(explode('/', trim($network), 2), 2, null);
            $net = @inet_pton((string) $base);
            if ($net === false || strlen($net) !== strlen($address)) {
                continue;
            }

            $bits = $bits === null ? strlen($net) * 8 : max(0, min(strlen($net) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($address, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rest === 0 || ((ord($address[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest) & 0xFF)) === 0) {
                return true;
            }
        }

        return false;
    }
}
