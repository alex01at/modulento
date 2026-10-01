<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class ReviewController extends Controller
{
    private const PER_PAGE = 50;

    /** The buyer of a finished order reviews it. */
    public function create(array $params): void
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);
        $account = $app->auth->account();

        // Anyone but the buyer gets the same answer as for an order that
        // does not exist.
        if ($order === null || $order['buyer_id'] !== $account['id']) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $problem = !$app->orders->isReviewable($order)
            ? 'core.review.error.not_possible'
            : $app->reviews->create(
                $order,
                $account['id'],
                (string) ($account['display_name'] ?? ''),
                (int) ($_POST['rating'] ?? 0),
                (string) ($_POST['body'] ?? ''),
                $app->translator->locale()
            );

        if ($problem !== null) {
            Session::flash('error', $this->trans($problem));
            $this->redirect('/orders/' . $order['id']);
            return;
        }

        $provider = $app->providers->find($order['provider_id']);
        if ($provider !== null && $provider['account_status'] === 'active') {
            $locale = $app->locales->isEnabled($provider['account_locale']) ? $provider['account_locale'] : $app->locales->default();
            $app->mailer->send($provider['account_email'], 'emails/review_new.txt.twig', [
                'number' => $order['number'],
                'title' => $order['offer_title'],
                'rating' => (int) $_POST['rating'],
                'body' => trim((string) ($_POST['body'] ?? '')),
                'link' => $app->url('/orders/' . $order['id'], $locale, true),
            ], $locale);
        }

        Session::flash('success', $this->trans('core.review.saved'));
        $this->redirect('/orders/' . $order['id']);
    }

    /** The provider answers the review of one of their orders, once and in public. */
    public function reply(array $params): void
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);
        $provider = $app->providers->findByAccount($app->auth->account()['id']);
        $review = $order !== null ? $app->reviews->findByOrder($order['id']) : null;

        if ($order === null || $provider === null || $order['provider_id'] !== (int) $provider['id'] || $review === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $problem = (new RateLimiter($app->db))->hit('review-reply', (string) $provider['id'], 30, 3600)
            ? 'core.error.too_many_requests'
            : $app->reviews->reply((int) $review['id'], (string) ($_POST['reply'] ?? ''));

        if ($problem === null) {
            $author = $review['author_id'] !== null ? $app->accounts->findById((int) $review['author_id']) : null;
            if ($author !== null && $author['status'] === 'active') {
                $locale = $app->locales->isEnabled($author['locale']) ? $author['locale'] : $app->locales->default();
                $app->mailer->send($author['email'], 'emails/review_reply.txt.twig', [
                    'title' => $order['offer_title'],
                    'provider' => $order['provider_name'],
                    'reply' => trim((string) $_POST['reply']),
                    'link' => $app->url('/orders/' . $order['id'], $locale, true),
                ], $locale);
            }
        }

        Session::flash($problem === null ? 'success' : 'error', $this->trans($problem ?? 'core.review.reply_saved'));
        $this->redirect('/orders/' . $order['id']);
    }

    // --- Administration -----------------------------------------------------------

    public function index(array $params): void
    {
        $status = in_array($_GET['status'] ?? '', ['published', 'hidden'], true) ? $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->reviews->listAll($status, $page, self::PER_PAGE);

        $this->render('@admin/reviews.twig', [
            'reviews' => $list['rows'],
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function hide(array $params): void
    {
        $app = $this->app;
        $review = $app->reviews->find((int) $params['id']);
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($review === null) {
            $this->redirect('/admin/reviews');
            return;
        }
        // Whoever's review is taken down is told why.
        if ($note === '') {
            Session::flash('error', $this->trans('core.admin.providers.error.note'));
            $this->redirect('/admin/reviews');
            return;
        }

        $app->reviews->setStatus((int) $review['id'], 'hidden', $note);

        $author = $review['author_id'] !== null ? $app->accounts->findById((int) $review['author_id']) : null;
        if ($author !== null && $author['status'] === 'active') {
            $locale = $app->locales->isEnabled($author['locale']) ? $author['locale'] : $app->locales->default();
            $app->mailer->send($author['email'], 'emails/review_hidden.txt.twig', ['note' => $note], $locale);
        }

        Session::flash('success', $this->trans('core.admin.reviews.hidden'));
        $this->redirect('/admin/reviews');
    }

    public function show(array $params): void
    {
        $this->app->reviews->setStatus((int) $params['id'], 'published', null);
        Session::flash('success', $this->trans('core.admin.reviews.shown'));
        $this->redirect('/admin/reviews');
    }
}
