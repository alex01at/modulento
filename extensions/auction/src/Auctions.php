<?php

declare(strict_types=1);

namespace Modulento\Auction;

use Modulento\Core\App;
use Modulento\Core\Event\OfferStatusChanged;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\OrderNotifier;
use Modulento\Core\Support\Clock;
use PDO;
use Throwable;

/**
 * Lots and bids.
 *
 * A bid is accepted by one UPDATE that only succeeds while the lot is
 * still open and the amount still reaches what the next bid must reach, so
 * of two people bidding at the same moment exactly one wins. Closing works
 * the same way: the lot leaves "open" in one UPDATE, and only the request
 * that made that change creates the order.
 */
final class Auctions
{
    public const DURATIONS = [1, 3, 5, 7, 10, 14];
    public const MIN_PRICE = 100;
    public const MAX_PRICE = 10_000_000;
    public const DEFAULT_STEP = 100;

    /** A bid in the last minutes keeps the lot open this long, so the others can answer. */
    private const EXTEND_SECONDS = 120;

    public function __construct(private PDO $db)
    {
    }

    public function lot(int $offerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_auction_lot WHERE offer_id = :id');
        $stmt->execute(['id' => $offerId]);
        $lot = $stmt->fetch();
        if (!$lot) {
            return null;
        }

        foreach (['offer_id', 'start_price', 'step', 'duration_days', 'next_min', 'bid_count'] as $field) {
            $lot[$field] = (int) $lot[$field];
        }
        foreach (['current_price', 'order_id'] as $field) {
            $lot[$field] = $lot[$field] !== null ? (int) $lot[$field] : null;
        }

        return $lot;
    }

    /** Whether the lot's terms are fixed: it is running or was sold. */
    public static function isLocked(?array $lot): bool
    {
        return $lot !== null && in_array($lot['status'], ['open', 'sold'], true);
    }

    /** Creates the lot, or sets up one that ended without a sale to run again. A running or sold lot is left alone. */
    public function saveLot(int $offerId, int $startPrice, int $step, int $durationDays): void
    {
        $values = ['id' => $offerId, 'start' => $startPrice, 'step' => $step, 'days' => $durationDays, 'next' => $startPrice];

        if ($this->lot($offerId) === null) {
            $stmt = $this->db->prepare(
                "INSERT INTO x_auction_lot (offer_id, start_price, step, duration_days, status, next_min) VALUES (:id, :start, :step, :days, 'pending', :next)"
            );
            $stmt->execute($values);

            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE x_auction_lot SET start_price = :start, step = :step, duration_days = :days, status = 'pending', next_min = :next,
                 current_price = NULL, bid_count = 0, ends_at = NULL, closed_at = NULL, order_id = NULL
             WHERE offer_id = :id AND status NOT IN ('open', 'sold')"
        );
        $stmt->execute($values);
        if ($stmt->rowCount() === 1) {
            $delete = $this->db->prepare('DELETE FROM x_auction_bid WHERE offer_id = :id');
            $delete->execute(['id' => $offerId]);
        }
    }

    /** Starts the clock of a waiting lot. Called when its offer becomes public. */
    public function start(int $offerId): bool
    {
        $lot = $this->lot($offerId);
        if ($lot === null || $lot['status'] !== 'pending') {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE x_auction_lot SET status = 'open', ends_at = :ends WHERE offer_id = :id AND status = 'pending'");
        $stmt->execute(['ends' => Clock::now($lot['duration_days'] * 86400), 'id' => $offerId]);

        return $stmt->rowCount() === 1;
    }

    public function isRunning(?array $lot): bool
    {
        return $lot !== null && $lot['status'] === 'open' && $lot['ends_at'] > Clock::now();
    }

    /**
     * Places a bid.
     *
     * @return string|null language key of the reason it was refused, null on success
     */
    public function bid(int $offerId, int $accountId, int $amount, bool $termsAccepted): ?string
    {
        $lot = $this->lot($offerId);
        if (!$this->isRunning($lot)) {
            return 'auction.error.closed';
        }
        if ($amount < $lot['next_min']) {
            return 'auction.error.too_low';
        }
        if ($amount > self::MAX_PRICE) {
            return 'auction.error.too_high';
        }
        if (($this->highBid($offerId)['account_id'] ?? null) === $accountId) {
            return 'auction.error.already_highest';
        }

        $now = Clock::now();
        $ends = $lot['ends_at'] < Clock::now(self::EXTEND_SECONDS) ? Clock::now(self::EXTEND_SECONDS) : $lot['ends_at'];

        $this->db->beginTransaction();

        // The conditions repeat what was checked above, now against the row
        // as it is at this moment.
        $stmt = $this->db->prepare(
            "UPDATE x_auction_lot SET current_price = :amount, next_min = :next, bid_count = bid_count + 1, ends_at = :ends
             WHERE offer_id = :id AND status = 'open' AND ends_at > :now AND next_min <= :reached"
        );
        $stmt->execute(['amount' => $amount, 'next' => $amount + $lot['step'], 'ends' => $ends, 'id' => $offerId, 'now' => $now, 'reached' => $amount]);

        if ($stmt->rowCount() !== 1) {
            $this->db->rollBack();

            return 'auction.error.outbid';
        }

        $insert = $this->db->prepare(
            'INSERT INTO x_auction_bid (offer_id, account_id, amount, terms_accepted, created_at) VALUES (:offer, :account, :amount, :terms, :now)'
        );
        $insert->execute(['offer' => $offerId, 'account' => $accountId, 'amount' => $amount, 'terms' => $termsAccepted ? 1 : 0, 'now' => $now]);
        $this->db->commit();

        return null;
    }

    /** The leading bid of an account that can still buy. */
    public function highBid(int $offerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT b.* FROM x_auction_bid b JOIN account a ON a.id = b.account_id
             WHERE b.offer_id = :id AND a.status = 'active' ORDER BY b.amount DESC, b.id LIMIT 1"
        );
        $stmt->execute(['id' => $offerId]);
        $bid = $stmt->fetch();
        if (!$bid) {
            return null;
        }

        foreach (['id', 'offer_id', 'account_id', 'amount', 'terms_accepted'] as $field) {
            $bid[$field] = (int) $bid[$field];
        }

        return $bid;
    }

    /**
     * The newest bids, without saying who placed them: bidders are
     * numbered in the order in which they joined.
     *
     * @return array<int, array{bidder: int, account_id: int, amount: int, created_at: string}>
     */
    public function bids(int $offerId, int $limit): array
    {
        $stmt = $this->db->prepare('SELECT account_id, MIN(id) AS first_bid FROM x_auction_bid WHERE offer_id = :id GROUP BY account_id ORDER BY first_bid');
        $stmt->execute(['id' => $offerId]);
        $numbers = [];
        foreach ($stmt->fetchAll() as $row) {
            $numbers[(int) $row['account_id']] = count($numbers) + 1;
        }

        $stmt = $this->db->prepare('SELECT account_id, amount, created_at FROM x_auction_bid WHERE offer_id = :id ORDER BY id DESC LIMIT ' . max(1, $limit));
        $stmt->execute(['id' => $offerId]);

        return array_map(fn (array $bid) => [
            'bidder' => $numbers[(int) $bid['account_id']],
            'account_id' => (int) $bid['account_id'],
            'amount' => (int) $bid['amount'],
            'created_at' => $bid['created_at'],
        ], $stmt->fetchAll());
    }

    /** @return array<int, array{offer_id: int, amount: int, created_at: string}> every bid of an account, for its data export */
    public function bidsOfAccount(int $accountId): array
    {
        $stmt = $this->db->prepare('SELECT offer_id, amount, created_at FROM x_auction_bid WHERE account_id = :id ORDER BY id');
        $stmt->execute(['id' => $accountId]);

        return array_map(fn (array $bid) => ['offer_id' => (int) $bid['offer_id'], 'amount' => (int) $bid['amount'], 'created_at' => $bid['created_at']], $stmt->fetchAll());
    }

    /**
     * Brings running lots back in line with their bids after bids were
     * removed with the account that placed them.
     */
    public function resyncOpen(App $app): void
    {
        foreach ($this->db->query("SELECT offer_id FROM x_auction_lot WHERE status = 'open' AND bid_count > 0")->fetchAll(PDO::FETCH_COLUMN) as $offerId) {
            $lot = $this->lot((int) $offerId);
            $stmt = $this->db->prepare('SELECT COUNT(*) AS bids, MAX(amount) AS top FROM x_auction_bid WHERE offer_id = :id');
            $stmt->execute(['id' => $offerId]);
            $left = $stmt->fetch();
            if ((int) $left['bids'] === $lot['bid_count']) {
                continue;
            }

            $top = $left['top'] !== null ? (int) $left['top'] : null;
            $update = $this->db->prepare("UPDATE x_auction_lot SET current_price = :price, next_min = :next, bid_count = :bids WHERE offer_id = :id AND status = 'open'");
            $update->execute(['price' => $top, 'next' => $top !== null ? $top + $lot['step'] : $lot['start_price'], 'bids' => (int) $left['bids'], 'id' => $offerId]);
            $app->offers->setPriceFrom((int) $offerId, $top ?? $lot['start_price']);
        }
    }

    /** Ends every lot whose time is up. @return int how many */
    public function closeDue(App $app): int
    {
        $stmt = $this->db->prepare("SELECT offer_id FROM x_auction_lot WHERE status = 'open' AND ends_at <= :now ORDER BY ends_at LIMIT 100");
        $stmt->execute(['now' => Clock::now()]);

        $closed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $offerId) {
            if ($this->close((int) $offerId, $app)) {
                $closed++;
            }
        }

        return $closed;
    }

    private function close(int $offerId, App $app): bool
    {
        $offer = $app->offers->find($offerId);
        $winner = $this->highBid($offerId);
        $flow = $app->orders->flow(SaleFlow::ID);

        // Pausing the offer does not take a lot out of its auction; a
        // provider or an offer the platform has taken down does.
        $sellable = $offer !== null && $flow !== null && in_array($offer['status'], ['published', 'paused'], true)
            && $offer['provider_status'] === 'approved' && $offer['account_status'] === 'active';
        $status = !$sellable ? 'cancelled' : ($winner !== null ? 'sold' : 'unsold');

        // ends_at is checked again: a bid in the last second moves it.
        $now = Clock::now();
        $stmt = $this->db->prepare("UPDATE x_auction_lot SET status = :status, closed_at = :now WHERE offer_id = :id AND status = 'open' AND ends_at <= :due");
        $stmt->execute(['status' => $status, 'now' => $now, 'id' => $offerId, 'due' => $now]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }

        if ($status === 'sold') {
            try {
                $this->createOrder($offer, $winner, $flow, $app);
            } catch (Throwable $e) {
                // Without its order the lot is not sold; the next run tries again.
                $reopen = $this->db->prepare("UPDATE x_auction_lot SET status = 'open', closed_at = NULL WHERE offer_id = :id");
                $reopen->execute(['id' => $offerId]);

                throw $e;
            }
        } elseif ($status === 'unsold') {
            $locale = $app->locales->isEnabled($offer['account_locale']) ? $offer['account_locale'] : $app->locales->default();
            $app->mailer->send($offer['account_email'], '@auction/emails/unsold.txt.twig', [
                'title' => $app->offers->text($offer, $locale)['title'] ?? '',
                'link' => $app->url('/account/offers/' . $offerId, $locale, true),
            ], $locale);
        }

        // An ended lot leaves the catalogue.
        if ($offer !== null && $offer['status'] === 'published') {
            $app->offers->setStatus($offerId, 'paused', null, null);
            $app->events->dispatch(new OfferStatusChanged($offerId, $offer['provider_id'], 'published', 'paused'));
        }

        return true;
    }

    private function createOrder(array $offer, array $winner, OrderFlow $flow, App $app): void
    {
        $buyer = $app->accounts->findById($winner['account_id']);
        $buyer['id'] = (int) $buyer['id'];
        $locale = $app->locales->isEnabled((string) $buyer['locale']) ? (string) $buyer['locale'] : $app->locales->default();
        $title = $app->offers->text($offer, $locale)['title'] ?? '';
        $lot = $this->lot($offer['id']);

        $orderId = $app->orders->create(
            $buyer,
            $offer,
            $title,
            $flow,
            [['label' => $title, 'quantity' => 1, 'unit_price' => $winner['amount']]],
            ['bids' => $lot['bid_count'], 'start_price' => $lot['start_price'], 'ended_at' => $lot['closed_at']],
            'core.offline',
            $locale,
            null,
            $winner['terms_accepted'] === 1
        );

        $stmt = $this->db->prepare('UPDATE x_auction_lot SET order_id = :order WHERE offer_id = :id');
        $stmt->execute(['order' => $orderId, 'id' => $offer['id']]);

        // Nobody clicked: both sides hear about the sale.
        OrderNotifier::stateChanged($app, null, $app->orders->find($orderId), 'place', 'system', null);
    }
}
