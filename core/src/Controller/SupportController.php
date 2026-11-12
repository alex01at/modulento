<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\License\LicenseKeys;
use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\SupportNotices;
use Modulento\Core\Support\TicketClient;

/**
 * Administration -> Support: tickets against the configured license
 * server, for whatever this installation already holds a license key for
 * (a per-product key, or a single master key covering every product). No
 * key stored at all means nothing to authenticate a ticket with, so the
 * page shows a plain "you need a license first" state rather than a form -
 * this is vendor support for a paid product, not a general-purpose
 * helpdesk.
 *
 * A ticket belongs to exactly one license server-side; this installation
 * does not remember which stored key a given reference was opened under,
 * so show()/reply() try each distinct key it holds in turn until one of
 * them is recognised as the ticket's own - see withOwningCredential()'s
 * own doc comment for why that has to dedupe by key and stop at the
 * first answer that is not simply "not this key".
 */
final class SupportController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $credentials = $this->credentials();
        $domain = (string) ($app->config['app']['url'] ?? '');

        // The same physical key often sits under more than one credential
        // here (a master key an app installed from the Shop is also
        // copied under that product's own id - ShopController::install())
        // - asking twice for the same key would just answer with the same
        // tickets twice, so both the asking and the merging dedupe.
        $tickets = [];
        $seenKeys = [];
        $seenReferences = [];
        foreach ($credentials as $credential) {
            if (isset($seenKeys[$credential['key']])) {
                continue;
            }
            $seenKeys[$credential['key']] = true;

            $result = $this->client()->list($credential['key'], $domain);
            if ($result['ok']) {
                foreach ($result['tickets'] as $ticket) {
                    if (!isset($seenReferences[$ticket['reference']])) {
                        $seenReferences[$ticket['reference']] = true;
                        $tickets[] = $ticket;
                    }
                }
            }
        }
        usort($tickets, fn (array $a, array $b) => strcmp($b['updated_at'], $a['updated_at']));

        $this->render('@admin/support.twig', [
            'credentials' => $credentials,
            'tickets' => $tickets,
            'enabled' => (string) ($app->config['update']['license_server'] ?? '') !== '',
        ]);
    }

    public function create(array $params): void
    {
        $app = $this->app;
        $credentialId = (string) ($_POST['credential'] ?? '');
        $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200);
        $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 5000);

        $credential = $this->findCredential($credentialId);
        $attachment = $this->readAttachment();
        if ($credential === null || $subject === '' || $message === '' || $attachment === false) {
            Session::flash('error', $this->trans($attachment === false ? 'core.admin.support.error.attachment_rejected' : 'core.admin.support.error.form'));
            $this->redirect('/admin/support');
            return;
        }

        $product = $credential['id'] !== LicenseKeys::MASTER_ID ? $credential['id'] : null;
        $result = $this->client()->open($credential['key'], (string) ($app->config['app']['url'] ?? ''), $product, $subject, $message, $attachment);

        if (!$result['ok']) {
            Session::flash('error', $this->trans($this->errorKey($result['error'] ?? 'unavailable')));
            $this->redirect('/admin/support');
            return;
        }

        Session::flash('success', $this->trans('core.admin.support.created'));
        $this->redirect('/admin/support/' . $result['reference']);
    }

    public function show(array $params): void
    {
        $app = $this->app;
        $domain = (string) ($app->config['app']['url'] ?? '');
        $reference = (string) $params['reference'];

        $result = $this->withOwningCredential(fn (string $key) => $this->client()->thread($key, $domain, $reference));
        if ($result['ok']) {
            $lastMessage = end($result['messages']);
            SupportNotices::markSeen($app, $reference, $lastMessage !== false ? $lastMessage['created_at'] : '');
            $this->render('@admin/support_thread.twig', ['ticket' => $result]);
            return;
        }

        if ($result['error'] !== 'not_found') {
            Session::flash('error', $this->trans($this->errorKey($result['error'])));
        }
        $this->redirect('/admin/support');
    }

    public function reply(array $params): void
    {
        $app = $this->app;
        $domain = (string) ($app->config['app']['url'] ?? '');
        $reference = (string) $params['reference'];
        $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 5000);
        $attachment = $this->readAttachment();

        if ($message === '' || $attachment === false) {
            if ($attachment === false) {
                Session::flash('error', $this->trans('core.admin.support.error.attachment_rejected'));
            }
            $this->redirect('/admin/support/' . $reference);
            return;
        }

        $result = $this->withOwningCredential(fn (string $key) => $this->client()->reply($key, $domain, $reference, $message, $attachment));
        Session::flash(
            $result['ok'] ? 'success' : 'error',
            $this->trans($result['ok'] ? 'core.admin.support.replied' : $this->errorKey($result['error']))
        );
        $this->redirect('/admin/support/' . $reference);
    }

    /** Streams a message's attachment - the license server's own ownership check (message -> ticket -> license) is the only gate; a credential that does not own it just answers not_found, same as thread()/reply(). */
    public function attachment(array $params): void
    {
        $domain = (string) ($this->app->config['app']['url'] ?? '');
        $messageId = (int) $params['message_id'];

        $result = $this->withOwningCredential(fn (string $key) => $this->client()->downloadAttachment($key, $domain, $messageId));
        if (!$result['ok']) {
            $this->redirect('/admin/support');
            return;
        }

        // The filename is a display hint from the page that linked here
        // (already fetched, ownership-checked, over this same connection)
        // - never itself a path, so there is nothing for it to traverse.
        // Same header set as OrderController::sendFile() uses for an
        // order's own uploaded files - never shown by the browser,
        // whatever the file claims to be.
        $filename = basename((string) ($_GET['name'] ?? 'anhang'));
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . "\"; filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . strlen($result['content']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        echo $result['content'];
    }

    /**
     * @return array{name: string, content: string}|null|false null = no
     *         file chosen, false = a real upload problem (too big for
     *         this host's own upload_max_filesize, a failed transfer, ...)
     */
    private function readAttachment(): array|null|false
    {
        $file = $_FILES['attachment'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) {
            return false;
        }

        $content = file_get_contents($file['tmp_name']);

        return $content !== false ? ['name' => (string) $file['name'], 'content' => $content] : false;
    }

    /**
     * Tries every distinct stored key in turn against $call (thread() or
     * reply() for $reference) until one of them is recognised as the
     * ticket's own - the same key stored under more than one credential
     * (a master key an app installed from the Shop also copies under
     * that product's own id - ShopController::install()) is asked only
     * once, never once per credential: with reply(), asking the very
     * same key a second time would resend the very same reply, not just
     * waste a request. A key's own answer that is not "not_found" (rate
     * limited, revoked, unreachable, ...) is never worth masking by
     * trying the next key instead - it is returned immediately, as the
     * real reason this failed.
     *
     * @return array{ok: bool, error?: string, ...}
     */
    private function withOwningCredential(callable $call): array
    {
        $tried = [];
        $lastError = 'not_found';
        foreach ($this->credentials() as $credential) {
            if (isset($tried[$credential['key']])) {
                continue;
            }
            $tried[$credential['key']] = true;

            $result = $call($credential['key']);
            if ($result['ok']) {
                return $result;
            }
            $lastError = $result['error'] ?? 'unavailable';
            if ($lastError !== 'not_found') {
                return $result;
            }
        }

        return ['ok' => false, 'error' => $lastError];
    }

    /** @return array<int, array{id: string, label: string, key: string}> every license key this installation actually holds, usable for a ticket right away */
    private function credentials(): array
    {
        return array_map(
            fn (array $usable) => $usable + ['label' => $usable['id'] === LicenseKeys::MASTER_ID ? $this->trans('core.admin.support.master') : $usable['id']],
            $this->app->licenseKeys->usable()
        );
    }

    private function findCredential(string $id): ?array
    {
        foreach ($this->credentials() as $credential) {
            if ($credential['id'] === $id) {
                return $credential;
            }
        }

        return null;
    }

    private function client(): TicketClient
    {
        $app = $this->app;

        return new TicketClient(
            $app->config['update']['license_http'] ?? new CurlHttpClient(),
            (string) ($app->config['update']['license_server'] ?? '')
        );
    }

    private function errorKey(string $error): string
    {
        return match ($error) {
            'expired' => 'core.admin.support.error.expired',
            'domain_mismatch' => 'core.admin.support.error.domain_mismatch',
            'revoked' => 'core.admin.support.error.revoked',
            'not_found' => 'core.admin.support.error.not_found',
            'rate_limited' => 'core.admin.support.error.rate_limited',
            'attachment_rejected' => 'core.admin.support.error.attachment_rejected',
            default => 'core.admin.support.error.unavailable',
        };
    }
}
