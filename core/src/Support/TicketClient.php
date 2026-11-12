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

    /**
     * $attachment, when given, is ['name' => original filename, 'content'
     * => raw bytes] - base64-encoded here, the same way everything else
     * in this body travels, never multipart. The license server checks
     * size/type itself (Support\AttachmentStorage on that side) and
     * answers 'attachment_rejected' if it does not qualify - nothing here
     * duplicates that check, a customer just sees that answer translated.
     *
     * @param array{name: string, content: string}|null $attachment
     * @return array{ok: bool, reference?: string, status?: string, error?: string}
     */
    public function open(string $licenseKey, string $domain, ?string $product, string $subject, string $message, ?array $attachment = null): array
    {
        $body = ['license_key' => $licenseKey, 'domain' => $domain, 'product' => $product ?? '', 'subject' => $subject, 'message' => $message];
        if ($attachment !== null) {
            $body['attachment'] = ['name' => $attachment['name'], 'content' => base64_encode($attachment['content'])];
        }

        return $this->call('/v1/tickets', $body);
    }

    /** @return array{ok: bool, tickets?: list<array{reference: string, subject: string, status: string, updated_at: string, last_sender: string, last_message_at: string}>, error?: string} */
    public function list(string $licenseKey, string $domain): array
    {
        return $this->call('/v1/tickets/list', ['license_key' => $licenseKey, 'domain' => $domain]);
    }

    /** @return array{ok: bool, reference?: string, subject?: string, status?: string, messages?: list<array{id: int, sender: string, body: string, created_at: string, attachment: ?string}>, error?: string} */
    public function thread(string $licenseKey, string $domain, string $reference): array
    {
        return $this->call('/v1/tickets/thread', ['license_key' => $licenseKey, 'domain' => $domain, 'reference' => $reference]);
    }

    /**
     * @param array{name: string, content: string}|null $attachment see open()'s own doc comment
     * @return array{ok: bool, status?: string, error?: string}
     */
    public function reply(string $licenseKey, string $domain, string $reference, string $message, ?array $attachment = null): array
    {
        $body = ['license_key' => $licenseKey, 'domain' => $domain, 'reference' => $reference, 'message' => $message];
        if ($attachment !== null) {
            $body['attachment'] = ['name' => $attachment['name'], 'content' => base64_encode($attachment['content'])];
        }

        return $this->call('/v1/tickets/reply', $body);
    }

    /** @return array{ok: bool, content?: string, error?: string} the raw bytes on success - the license server's own ownership check (message -> ticket -> license) is the only gate, same as everything else this key can read. */
    public function downloadAttachment(string $licenseKey, string $domain, int $messageId): array
    {
        if ($this->serverUrl === '') {
            return ['ok' => false, 'error' => 'unavailable'];
        }

        $response = $this->http->request(
            'POST',
            rtrim($this->serverUrl, '/') . '/v1/tickets/attachment',
            ['Content-Type' => 'application/json', 'Accept' => 'application/octet-stream'],
            json_encode(['license_key' => $licenseKey, 'domain' => $domain, 'message_id' => $messageId], JSON_UNESCAPED_SLASHES)
        );

        if ($response['status'] === 200) {
            return ['ok' => true, 'content' => $response['body']];
        }

        $decoded = json_decode($response['body'], true);
        $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unavailable';

        return ['ok' => false, 'error' => $error];
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
