<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * Every conversation of the logged-in account in one place - order
 * messages and offer questions mixed, newest activity first. A Postfach:
 * it only lists and links on; replying still happens on the order or the
 * offer page, where it already works.
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
            'type' => 'order',
            'context' => '#' . $row['order_number'] . ' – ' . $row['offer_title'],
            'counterpart' => $row['counterpart'],
            'body' => mb_strimwidth(str_replace("\n", ' ', $row['body']), 0, self::SNIPPET_LENGTH, '…'),
            'hidden' => $row['status'] === 'hidden',
            'created_at' => $row['created_at'],
            'unread' => $row['unread'],
            'path' => '/orders/' . $row['order_id'],
        ], $app->orders->inboxThreads($accountId, $providerId));

        $offerThreads = array_filter(array_map(function (array $row) use ($app, $locale, $accountId) {
            $offer = $app->offers->find($row['offer_id']);
            if ($offer === null) {
                return null;
            }
            $text = $app->offers->text($offer, $locale);

            return [
                'type' => 'offer',
                'context' => $text['title'] ?? '',
                'counterpart' => $row['is_asker'] ? $offer['provider_name'] : ($row['asker_name'] ?: $this->trans('core.offer.contact.asker')),
                'body' => mb_strimwidth(str_replace("\n", ' ', $row['body']), 0, self::SNIPPET_LENGTH, '…'),
                'hidden' => $row['status'] === 'hidden',
                'created_at' => $row['created_at'],
                'unread' => $row['unread'],
                'path' => '/offers/' . ($text['slug'] ?? $row['offer_id']) . ($row['is_asker'] ? '#messages' : '#thread-' . $row['asker_id']),
            ];
        }, $app->offerMessages->inboxThreads($accountId, $providerId)));

        $threads = [...$orderThreads, ...$offerThreads];
        usort($threads, fn (array $a, array $b) => $b['created_at'] <=> $a['created_at']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pages = max(1, (int) ceil(count($threads) / self::PER_PAGE));

        $this->render('account/inbox.twig', [
            'threads' => array_slice($threads, (min($page, $pages) - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
