<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Support tickets against a licensed product, opened with this
 * installation's own license key - the same license server
 * (core.update.license_server) and the same authentication
 * (LicenseServerClient's own doc comment explains the "domain" value: the
 * configured app URL, not a bare hostname) as a release check, just a
 * different endpoint. Pull only, same reasoning as everywhere else this
 * installation talks to that server: it can never reach this installation
 * on its own, so every call here is this installation asking.
 *
 * Stateless about which key to use - Controller\SupportController passes
 * whichever one a particular ticket belongs to, since one installation can
 * hold a separate key per product (or a single master key for all of
 * them).
 */
final class TicketClient
{
    public function __construct(private HttpClient $http, private string $serverUrl)
    {
    }

    /** @return array{ok: bool, reference?: string, status?: string, error?: string} */
    public function open(string $licenseKey, string $domain, ?string $product, string $subject, string $message): array
    {
        return $this->call('/v1/tickets', ['license_key' => $licenseKey, 'domain' => $domain, 'product' => $product ?? '', 'subject' => $subject, 'message' => $message]);
    }

    /** @return array{ok: bool, tickets?: list<array{reference: string, subject: string, status: string, updated_at: string}>, error?: string} */
    public function list(string $licenseKey, string $domain): array
    {
        return $this->call('/v1/tickets/list', ['license_key' => $licenseKey, 'domain' => $domain]);
    }

    /** @return array{ok: bool, reference?: string, subject?: string, status?: string, messages?: list<array{sender: string, body: string, created_at: string}>, error?: string} */
    public function thread(string $licenseKey, string $domain, string $reference): array
    {
        return $this->call('/v1/tickets/thread', ['license_key' => $licenseKey, 'domain' => $domain, 'reference' => $reference]);
    }

    /** @return array{ok: bool, status?: string, error?: string} */
    public function reply(string $licenseKey, string $domain, string $reference, string $message): array
    {
        return $this->call('/v1/tickets/reply', ['license_key' => $licenseKey, 'domain' => $domain, 'reference' => $reference, 'message' => $message]);
    }

    /** @return array{ok: bool, error?: string} */
    private function call(string $path, array $body): array
    {
        if ($this->serverUrl === '') {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        $response = $this->http->request(
            'POST',
            rtrim($this->serverUrl, '/') . $path,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode($body, JSON_UNESCAPED_SLASHES)
        );

        $decoded = json_decode($response['body'], true);
        if ($response['status'] === 200 && is_array($decoded)) {
            return ['ok' => true] + $decoded;
        }

        // A server that does not know these routes yet (or is unreachable)
        // answers with something other than this API's own JSON error
        // shape - treated the same as "not available right now", never as
        // a specific failure a customer could act on.
        $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unavailable';

        return ['ok' => false, 'error' => $error];
    }
}
