<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Review\AccountRatingView;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

/**
 * Rating another account directly - not one order, the account itself. See
 * Modulento\Core\Review\AccountRatings::canRate() for who may do that.
 */
final class AccountRatingController extends Controller
{
    private const PER_PAGE = 50;

    /** The account's own "about me": every rating it has, a hidden one with its reason. */
    public function mine(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $app->accountRatings->aboutAccount($accountId, $page, self::PER_PAGE);

        $this->render('account/ratings.twig', [
            'account_ratings' => AccountRatingView::all($list['rows']),
            'can_reply_to' => array_column(array_filter($list['rows'], fn (array $r) => $r['reply'] === null && $r['status'] === 'published'), 'id'),
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    /** The logged-in account rates the account named in the address. */
    public function create(array $params): void
    {
        $app = $this->app;
        $account = $app->auth->account();
        $ratedId = (int) $params['id'];
        $rated = $app->accounts->findById($ratedId);
        $back = $this->safeReturn('/account');

        if ($rated === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $problem = (new RateLimiter($app->db))->hit('account-rating', (string) $account['id'], 10, 3600)
            ? 'core.error.too_many_requests'
            : $app->accountRatings->create(
                $account['id'],
                (string) ($account['display_name'] ?? ''),
                $ratedId,
                (int) ($_POST['rating'] ?? 0),
                (string) ($_POST['body'] ?? ''),
                $app->translator->locale()
            );

        if ($problem !== null) {
            Session::flash('error', $this->trans($problem));
            $this->redirect($back);
            return;
        }

        if ($rated['status'] === 'active') {
            $locale = $app->locales->isEnabled($rated['locale']) ? $rated['locale'] : $app->locales->default();
            $app->mailer->send($rated['email'], 'emails/account_rating_new.txt.twig', [
                'rating' => (int) $_POST['rating'],
                'body' => trim((string) ($_POST['body'] ?? '')),
                'link' => $app->url('/account', $locale, true),
            ], $locale);
        }

        Session::flash('success', $this->trans('core.account_rating.saved'));
        $this->redirect($back);
    }

    /** The rated account answers, once and in public. */
    public function reply(array $params): void
    {
        $app = $this->app;
        $account = $app->auth->account();
        $rating = $app->accountRatings->find((int) $params['id']);
        $back = $this->safeReturn('/account');

        if ($rating === null || (int) $rating['rated_id'] !== (int) $account['id']) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $problem = (new RateLimiter($app->db))->hit('account-rating-reply', (string) $account['id'], 30, 3600)
            ? 'core.error.too_many_requests'
            : $app->accountRatings->reply((int) $rating['id'], (string) ($_POST['reply'] ?? ''));

        if ($problem === null && $rating['rater_id'] !== null) {
            $rater = $app->accounts->findById((int) $rating['rater_id']);
            if ($rater !== null && $rater['status'] === 'active') {
                $locale = $app->locales->isEnabled($rater['locale']) ? $rater['locale'] : $app->locales->default();
                $app->mailer->send($rater['email'], 'emails/account_rating_reply.txt.twig', [
                    'reply' => trim((string) $_POST['reply']),
                    'link' => $app->url('/account', $locale, true),
                ], $locale);
            }
        }

        Session::flash($problem === null ? 'success' : 'error', $this->trans($problem ?? 'core.account_rating.reply_saved'));
        $this->redirect($back);
    }

    // --- Administration -----------------------------------------------------------

    public function index(array $params): void
    {
        $status = in_array($_GET['status'] ?? '', ['published', 'hidden'], true) ? $_GET['status'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $list = $this->app->accountRatings->listAll($status, $page, self::PER_PAGE);

        $this->render('@admin/account_ratings.twig', [
            'ratings' => $list['rows'],
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    public function hide(array $params): void
    {
        $app = $this->app;
        $rating = $app->accountRatings->find((int) $params['id']);
        $note = trim((string) ($_POST['note'] ?? ''));

        if ($rating === null) {
            $this->redirect('/admin/account-ratings');
            return;
        }
        if ($note === '') {
            Session::flash('error', $this->trans('core.admin.providers.error.note'));
            $this->redirect('/admin/account-ratings');
            return;
        }

        $app->accountRatings->setStatus((int) $rating['id'], 'hidden', $note);

        $rater = $rating['rater_id'] !== null ? $app->accounts->findById((int) $rating['rater_id']) : null;
        if ($rater !== null && $rater['status'] === 'active') {
            $locale = $app->locales->isEnabled($rater['locale']) ? $rater['locale'] : $app->locales->default();
            $app->mailer->send($rater['email'], 'emails/account_rating_hidden.txt.twig', ['note' => $note], $locale);
        }

        Session::flash('success', $this->trans('core.admin.account_ratings.hidden'));
        $this->redirect('/admin/account-ratings');
    }

    public function show(array $params): void
    {
        $this->app->accountRatings->setStatus((int) $params['id'], 'published', null);
        Session::flash('success', $this->trans('core.admin.account_ratings.shown'));
        $this->redirect('/admin/account-ratings');
    }
}
