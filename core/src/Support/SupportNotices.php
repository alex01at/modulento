<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\App;

/**
 * The administration bell's own count for Support (Controller\SupportController):
 * unread operator replies. The bell itself (App::addAdminNotice()) is
 * checked on every page load, so its closure may never talk to the
 * license server itself - it only ever reads a count this class already
 * cached in a setting. A scheduled task (Kernel::registerCore()) keeps
 * that count fresh periodically; opening a ticket's own thread
 * (SupportController::show()) also corrects it immediately, rather than
 * waiting for the next scheduled run.
 *
 * "Unread" means: the operator wrote the most recent message - never the
 * ticket's status, since an operator can reply and close in the same
 * step, and that reply should still raise the bell. Tracked per
 * reference against the timestamp of the last message this installation
 * has actually displayed, never against a merely local clock (a
 * server-issued timestamp is the only one both sides agree on).
 */
final class SupportNotices
{
    private const SETTING_SEEN = 'core.support.seen';
    private const SETTING_UNREAD = 'core.support.unread';

    public static function unreadCount(App $app): int
    {
        return count(self::readList($app, self::SETTING_UNREAD));
    }

    /** Called once a ticket's thread has actually been displayed - clears it from the bell right away. */
    public static function markSeen(App $app, string $reference, string $lastMessageAt): void
    {
        $seen = self::readMap($app, self::SETTING_SEEN);
        $seen[$reference] = $lastMessageAt;
        $app->settings->set(self::SETTING_SEEN, json_encode($seen) ?: '{}');

        $unread = array_values(array_diff(self::readList($app, self::SETTING_UNREAD), [$reference]));
        $app->settings->set(self::SETTING_UNREAD, json_encode($unread) ?: '[]');
    }

    /** Asks the license server for every ticket this installation's keys can see, and recomputes the unread set from scratch. */
    public static function refresh(App $app): void
    {
        $serverUrl = (string) ($app->config['update']['license_server'] ?? '');
        if ($serverUrl === '') {
            return;
        }
        $domain = (string) ($app->config['app']['url'] ?? '');
        $client = new TicketClient($app->config['update']['license_http'] ?? new CurlHttpClient(), $serverUrl);
        $seen = self::readMap($app, self::SETTING_SEEN);

        $unread = [];
        $askedKeys = [];
        foreach ($app->licenseKeys->usable() as $credential) {
            if (isset($askedKeys[$credential['key']])) {
                continue;
            }
            $askedKeys[$credential['key']] = true;

            $result = $client->list($credential['key'], $domain);
            if (!$result['ok']) {
                continue;
            }
            foreach ($result['tickets'] as $ticket) {
                if ($ticket['last_sender'] === 'operator' && ($seen[$ticket['reference']] ?? '') < $ticket['last_message_at']) {
                    $unread[$ticket['reference']] = true;
                }
            }
        }

        $app->settings->set(self::SETTING_UNREAD, json_encode(array_keys($unread)) ?: '[]');
    }

    /** @return array<int, string> */
    private static function readList(App $app, string $setting): array
    {
        $decoded = json_decode($app->settings->get($setting, '[]'), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return array<string, string> */
    private static function readMap(App $app, string $setting): array
    {
        $decoded = json_decode($app->settings->get($setting, '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }
}
