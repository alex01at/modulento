<?php

declare(strict_types=1);

namespace Modulento\Auction;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class BidController extends Controller
{
    private const BIDS_PER_HOUR = 30;

    /** A logged-in visitor bids on a running lot. A bid cannot be taken back. */
    public function bid(array $params): void
    {
        $app = $this->app;
        $account = $app->auth->account();
        $locale = $app->translator->locale();
        $offer = $app->offers->find((int) $params['id']);

        if ($offer === null || $offer['type'] !== LotType::ID || !$app->offers->isPublic($offer)) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $auctions = new Auctions($app->db);
        $before = $auctions->highBid($offer['id']);
        $amount = Money::parse(is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '');
        $mustAccept = $app->pages->links('terms', $locale) !== [];

        if ($offer['account_id'] === $account['id']) {
            $problem = 'auction.error.own';
        } elseif ($amount === null) {
            $problem = 'auction.error.amount';
        } elseif ($mustAccept && !isset($_POST['accept_terms'])) {
            $problem = 'core.register.error.terms';
        } elseif ((new RateLimiter($app->db))->hit('auction-bid', (string) $account['id'], self::BIDS_PER_HOUR, 3600)) {
            $problem = 'core.error.too_many_requests';
        } else {
            $problem = $auctions->bid($offer['id'], $account['id'], $amount, $mustAccept);
        }

        if ($problem !== null) {
            $min = $auctions->lot($offer['id'])['next_min'] ?? 0;
            Session::flash('error', $this->trans($problem, ['min' => Money::format($min, $offer['currency'], $locale)]));
        } else {
            $app->offers->setPriceFrom($offer['id'], $amount);
            $this->tellOutbid($offer, $before, $amount);
            Session::flash('success', $this->trans('auction.bid.placed', ['amount' => Money::format($amount, $offer['currency'], $locale)]));
        }

        $this->redirect('/offers/' . $app->offers->text($offer, $locale)['slug']);
    }

    /** The bidder who led until now learns that someone went higher. */
    private function tellOutbid(array $offer, ?array $before, int $amount): void
    {
        $app = $this->app;
        $previous = $before !== null ? $app->accounts->findById($before['account_id']) : null;
        if ($previous === null) {
            return;
        }

        $locale = $app->locales->isEnabled((string) $previous['locale']) ? (string) $previous['locale'] : $app->locales->default();
        $text = $app->offers->text($offer, $locale);

        $app->mailer->send($previous['email'], '@auction/emails/outbid.txt.twig', [
            'title' => $text['title'],
            'amount' => Money::format($amount, $offer['currency'], $locale),
            'link' => $app->url('/offers/' . $text['slug'], $locale, true),
        ], $locale);
    }
}
