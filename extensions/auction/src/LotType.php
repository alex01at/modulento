<?php

declare(strict_types=1);

namespace Modulento\Auction;

use Modulento\Core\App;
use Modulento\Core\Catalogue\OfferType;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Money;

final class LotType implements OfferType
{
    public const ID = 'auction.lot';

    private const BIDS_SHOWN = 10;

    public function id(): string
    {
        return self::ID;
    }

    public function labelKey(): string
    {
        return 'auction.type.lot';
    }

    public function formTemplate(): string
    {
        return '@auction/offer_form.twig';
    }

    public function detailTemplate(): string
    {
        return '@auction/offer_detail.twig';
    }

    public function formData(?int $offerId, ?array $typed, App $app): array
    {
        $locale = $app->translator->locale();
        $lot = $offerId !== null ? (new Auctions($app->db))->lot($offerId) : null;

        $data = [
            'durations' => Auctions::DURATIONS,
            'status' => $lot['status'] ?? null,
            'locked' => Auctions::isLocked($lot),
            'ends_at' => $lot['ends_at'] ?? null,
            'start_price' => $lot !== null ? Money::input($lot['start_price'], $locale) : '',
            'step' => Money::input($lot['step'] ?? Auctions::DEFAULT_STEP, $locale),
            'duration_days' => $lot['duration_days'] ?? 7,
        ];

        // Shown again after a failed validation: exactly what was typed.
        if ($typed !== null && !$data['locked']) {
            $data['start_price'] = is_string($typed['start_price'] ?? null) ? $typed['start_price'] : '';
            $data['step'] = is_string($typed['step'] ?? null) ? $typed['step'] : '';
            $data['duration_days'] = (int) ($typed['duration_days'] ?? 0);
        }

        return $data;
    }

    public function validate(array $input, ?int $offerId, App $app): array
    {
        // Once a lot runs, bidders rely on its terms; they stay as they are.
        if ($offerId !== null && Auctions::isLocked((new Auctions($app->db))->lot($offerId))) {
            return ['values' => ['locked' => true], 'errors' => []];
        }

        $errors = [];
        $text = fn (string $field) => is_string($input[$field] ?? null) ? trim($input[$field]) : '';

        $start = Money::parse($text('start_price'));
        if ($start === null || $start < Auctions::MIN_PRICE || $start > Auctions::MAX_PRICE) {
            $errors[] = 'auction.error.start_price';
        }

        $step = $text('step') === '' ? Auctions::DEFAULT_STEP : Money::parse($text('step'));
        if ($step === null || $step < 1 || $step > Auctions::MAX_PRICE) {
            $errors[] = 'auction.error.step';
        }

        $days = (int) $text('duration_days');
        if (!in_array($days, Auctions::DURATIONS, true)) {
            $errors[] = 'auction.error.duration';
        }

        return ['values' => ['locked' => false, 'start_price' => $start, 'step' => $step, 'duration_days' => $days], 'errors' => $errors];
    }

    public function save(int $offerId, array $values, App $app): ?int
    {
        $auctions = new Auctions($app->db);

        if (!$values['locked']) {
            $auctions->saveLot($offerId, $values['start_price'], $values['step'], $values['duration_days']);

            // A lot set up again while its offer is public runs at once.
            if (($app->offers->find($offerId)['status'] ?? null) === 'published') {
                $auctions->start($offerId);
            }
        }

        $lot = $auctions->lot($offerId);

        return $lot['current_price'] ?? $lot['start_price'];
    }

    public function detailData(int $offerId, string $locale, App $app): array
    {
        $auctions = new Auctions($app->db);
        $lot = $auctions->lot($offerId);
        if ($lot === null) {
            return ['status' => null];
        }

        $viewer = $app->auth->account()['id'] ?? null;
        $bids = $auctions->bids($offerId, self::BIDS_SHOWN);
        $running = $auctions->isRunning($lot);
        $left = $running ? max(0, strtotime($lot['ends_at'] . ' UTC') - strtotime(Clock::now() . ' UTC')) : 0;

        return [
            'offer_id' => $offerId,
            'currency' => $app->offers->find($offerId)['currency'] ?? $app->offers->currency(),
            'status' => $lot['status'],
            'running' => $running,
            'start_price' => $lot['start_price'],
            'current_price' => $lot['current_price'],
            'min_bid' => $lot['next_min'],
            'min_bid_input' => Money::input($lot['next_min'], $locale),
            'bid_count' => $lot['bid_count'],
            'duration_days' => $lot['duration_days'],
            'ends_at' => $lot['ends_at'],
            'left' => ['days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600), 'minutes' => intdiv($left % 3600, 60), 'seconds' => $left % 60],
            'ends_at_iso' => $lot['ends_at'] !== null ? str_replace(' ', 'T', $lot['ends_at']) . 'Z' : null,
            'now_iso' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'bids' => array_map(fn (array $bid) => [
                'bidder' => $bid['bidder'], 'amount' => $bid['amount'], 'created_at' => $bid['created_at'], 'own' => $bid['account_id'] === $viewer,
            ], $bids),
            'is_leading' => $viewer !== null && ($auctions->highBid($offerId)['account_id'] ?? null) === $viewer,
            'terms' => $app->pages->links('terms', $locale)[0] ?? null,
        ];
    }
}
