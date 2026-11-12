<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\Session;
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
 * so show()/reply() simply try every key it holds in turn until one of
 * them is recognised - with at most a small handful of keys per
 * installation in practice, that is cheaper than keeping a lookup table
 * only to save a few extra requests.
 */
final class SupportController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $credentials = $this->credentials();
        $domain = (string) ($app->config['app']['url'] ?? '');

        $tickets = [];
        foreach ($credentials as $credential) {
            $result = $this->client()->list($credential['key'], $domain);
            if ($result['ok']) {
                foreach ($result['tickets'] as $ticket) {
                    $tickets[] = $ticket;
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
        if ($credential === null || $subject === '' || $message === '') {
            Session::flash('error', $this->trans('core.admin.support.error.form'));
            $this->redirect('/admin/support');
            return;
        }

        $product = $credential['id'] !== '__master__' ? $credential['id'] : null;
        $result = $this->client()->open($credential['key'], (string) ($app->config['app']['url'] ?? ''), $product, $subject, $message);

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

        foreach ($this->credentials() as $credential) {
            $result = $this->client()->thread($credential['key'], $domain, $reference);
            if ($result['ok']) {
                $this->render('@admin/support_thread.twig', ['ticket' => $result]);
                return;
            }
        }

        $this->redirect('/admin/support');
    }

    public function reply(array $params): void
    {
        $app = $this->app;
        $domain = (string) ($app->config['app']['url'] ?? '');
        $reference = (string) $params['reference'];
        $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 5000);

        if ($message === '') {
            $this->redirect('/admin/support/' . $reference);
            return;
        }

        foreach ($this->credentials() as $credential) {
            $result = $this->client()->reply($credential['key'], $domain, $reference, $message);
            if ($result['ok']) {
                Session::flash('success', $this->trans('core.admin.support.replied'));
                $this->redirect('/admin/support/' . $reference);
                return;
            }
        }

        Session::flash('error', $this->trans('core.admin.support.error.not_found'));
        $this->redirect('/admin/support');
    }

    /** @return array<int, array{id: string, label: string, key: string}> every license key this installation actually holds, usable for a ticket right away */
    private function credentials(): array
    {
        $app = $this->app;
        $credentials = [];

        $master = $app->licenseKeys->findMaster();
        if ($master !== null) {
            $credentials[] = ['id' => '__master__', 'label' => $this->trans('core.admin.support.master'), 'key' => $master];
        }
        foreach ($app->licenseKeys->all() as $entry) {
            $key = $app->licenseKeys->find($entry['product_id']);
            if ($key !== null) {
                $credentials[] = ['id' => $entry['product_id'], 'label' => $entry['product_id'], 'key' => $key];
            }
        }

        return $credentials;
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
            default => 'core.admin.support.error.unavailable',
        };
    }
}
