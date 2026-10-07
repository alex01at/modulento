<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Every conversation of the logged-in account in one place - order
 * messages and offer questions mixed, newest activity first. A thread
 * opens in place (a <details> element, so it works without scripts) and
 * carries its own reply form, which posts to the same route the order's
 * or the offer's own page already uses - with "return" set back here, so
 * replying brings the account back to its Postfach instead of away from
 * it (Controller::safeReturn()).
 */
final class InboxController extends Controller
{
    private const PER_PAGE = 20;
    private const SNIPPET_LENGTH = 140;

    public function index(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $provider = $app->providers->findByAccount($accountId);
        $providerId = $provider !== null ? (int) $provider['id'] : null;
        $locale = $app->translator->locale();

        $orderThreads = array_map(fn (array $row) => [
            'source' => 'order',
            'type' => 'order',
            'context' => '#' . $row['order_number'] . ' – ' . $row['offer_title'],
            'counterpart' => $row['counterpart'],
            'body' => $this->snippet($row['body']),
            'hidden' => $row['status'] === 'hidden',
            'created_at' => $row['created_at'],
            'unread' => $row['unread'],
            'path' => '/orders/' . $row['order_id'],
            'order_id' => $row['order_id'],
        ], $app->orders->inboxThreads($accountId, $providerId));

        $offerThreads = array_filter(array_map(function (array $row) use ($app, $locale, $accountId) {
            $offer = $app->offers->find($row['offer_id']);
            if ($offer === null) {
                return null;
            }
            $text = $app->offers->text($offer, $locale);

            return [
                'source' => 'offer',
                'type' => 'offer',
                'context' => $text['title'] ?? '',
                'counterpart' => $row['is_asker'] ? $offer['provider_name'] : ($row['asker_name'] ?: $this->trans('core.offer.contact.asker')),
                'body' => $this->snippet($row['body']),
                'hidden' => $row['status'] === 'hidden',
                'created_at' => $row['created_at'],
                'unread' => $row['unread'],
                'path' => '/offers/' . ($text['slug'] ?? $row['offer_id']) . ($row['is_asker'] ? '#messages' : '#thread-' . $row['asker_id']),
                'offer_id' => (int) $row['offer_id'],
                'offer_slug' => $text['slug'] ?? (string) $row['offer_id'],
                'offer_account_id' => (int) $offer['account_id'],
                'is_asker' => $row['is_asker'],
                'asker_id' => $row['asker_id'],
            ];
        }, $app->offerMessages->inboxThreads($accountId, $providerId)));

        $threads = [...$orderThreads, ...$offerThreads];
        usort($threads, fn (array $a, array $b) => $b['created_at'] <=> $a['created_at']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pages = max(1, (int) ceil(count($threads) / self::PER_PAGE));

        // The full message history and the reply form's target are only
        // resolved for the threads actually shown on this page.
        $shown = array_map(
            fn (array $thread) => $thread + $this->detail($thread, $accountId, $providerId),
            array_slice($threads, (min($page, $pages) - 1) * self::PER_PAGE, self::PER_PAGE)
        );

        $this->render('account/inbox.twig', [
            'threads' => $shown,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** @return array{messages: array<int, array{mine: bool, label: string, body: string, hidden: bool, created_at: string}>, reply_action: string, reply_field: string} */
    private function detail(array $thread, int $accountId, ?int $providerId): array
    {
        $app = $this->app;

        if ($thread['source'] === 'order') {
            $order = $app->orders->find($thread['order_id']);
            $role = $order !== null ? $app->orders->roleOf($order, $accountId, $providerId) : null;

            return [
                'messages' => $order !== null ? array_map(fn (array $m) => [
                    'mine' => $m['author_role'] === $role,
                    'label' => $m['author_role'] === 'buyer' ? $order['buyer_name'] : $order['provider_name'],
                    'body' => $m['body'],
                    'hidden' => $m['status'] === 'hidden',
                    'created_at' => $m['created_at'],
                ], $order['messages']) : [],
                'reply_action' => '/orders/' . $thread['order_id'] . '/message',
                'reply_field' => 'body',
            ];
        }

        $rows = $app->offerMessages->thread($thread['offer_id'], $thread['asker_id']);

        return [
            'messages' => array_map(fn (array $m) => [
                'mine' => (int) $m['author_id'] === $accountId,
                'label' => $this->trans(match (true) {
                    (int) $m['author_id'] === $accountId => 'core.offer.contact.you',
                    (int) $m['author_id'] === $thread['offer_account_id'] => 'core.offer.contact.provider',
                    default => 'core.offer.contact.asker',
                }),
                'body' => $m['body'],
                'hidden' => ($m['status'] ?? 'visible') === 'hidden',
                'created_at' => $m['created_at'],
            ], $rows),
            'reply_action' => '/offers/' . $thread['offer_slug'] . ($thread['is_asker'] ? '/contact' : '/contact/' . $thread['asker_id']),
            'reply_field' => 'message',
        ];
    }

    private function snippet(string $body): string
    {
        return mb_strimwidth(str_replace("\n", ' ', $body), 0, self::SNIPPET_LENGTH, '…');
    }
}
