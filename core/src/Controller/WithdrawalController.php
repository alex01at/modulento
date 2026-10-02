<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\ClientIp;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

/**
 * The withdrawal form: a buyer declares in two steps that a contract is
 * withdrawn from, and receives an acknowledgement of receipt by e-mail.
 *
 * The platform only takes the declaration and passes it on. The contract is
 * with the provider, who decides whether a right of withdrawal exists - so
 * nothing here changes an order's state.
 */
final class WithdrawalController extends Controller
{
    private const MIN_FILL_SECONDS = 2;
    private const PER_PAGE = 50;
    private const MAX_OWN_ORDERS = 50;

    private const MAX_NAME = 150;
    private const MAX_EMAIL = 255;
    private const MAX_ORDER_NUMBER = 50;
    private const MAX_STATEMENT = 5000;

    // What was entered in the first step waits here for the second one.
    // Only checked values get in, and the second step takes nothing else.
    private const DRAFT = 'withdrawal_draft';
    private const FORM_AT = 'withdrawal_form_at';
    private const DONE = 'withdrawal_done';

    public function form(array $params): void
    {
        $draft = Session::get(self::DRAFT);
        $account = $this->app->auth->account();

        // Coming back from the second step to change something keeps what
        // was entered; otherwise a logged-in account's data is the start.
        $input = is_array($draft) ? $draft : [
            'name' => (string) ($account['display_name'] ?? ''),
            'email' => (string) ($account['email'] ?? ''),
            'order_number' => '',
            'statement' => '',
        ];
        if (is_string($_GET['order'] ?? null) && trim($_GET['order']) !== '') {
            $input['order_number'] = mb_substr(self::line($_GET['order']), 0, self::MAX_ORDER_NUMBER);
        }

        $this->renderForm($input, []);
    }

    /** First step: checks what was entered and shows it once more. Nothing is stored or sent yet. */
    public function review(array $params): void
    {
        $input = [
            'name' => self::line(self::posted('name')),
            'email' => Accounts::normalizeEmail(self::posted('email')),
            // A typed number wins over the one chosen from the list.
            'order_number' => self::line(self::posted('order_number')) ?: self::line(self::posted('order_choice')),
            'statement' => trim(str_replace("\r\n", "\n", self::posted('statement'))),
        ];
        $errors = [];

        if ($input['name'] === '' || mb_strlen($input['name']) > self::MAX_NAME) {
            $errors[] = $this->trans('core.withdrawal.error.name', ['max' => self::MAX_NAME]);
        }
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false || strlen($input['email']) > self::MAX_EMAIL) {
            $errors[] = $this->trans('core.register.error.email');
        }
        if ($input['order_number'] === '' || mb_strlen($input['order_number']) > self::MAX_ORDER_NUMBER) {
            $errors[] = $this->trans('core.withdrawal.error.order_number', ['max' => self::MAX_ORDER_NUMBER]);
        }
        if (mb_strlen($input['statement']) > self::MAX_STATEMENT) {
            $errors[] = $this->trans('core.withdrawal.error.statement', ['max' => self::MAX_STATEMENT]);
        }
        // A form sent back within a moment of being shown was not filled in
        // by a person. A person who really was that fast just sends it again.
        $shownAt = Session::get(self::FORM_AT);
        if ($errors === [] && ($shownAt === null || time() - (int) $shownAt < self::MIN_FILL_SECONDS)) {
            $errors[] = $this->trans('core.register.error.too_fast');
        }

        if ($errors !== []) {
            Session::remove(self::DRAFT);
            $this->renderForm($input, $errors);
            return;
        }

        // A field no person sees or fills in. A bot that does walks through
        // the same pages as everyone else, and nothing is stored or sent.
        $input['trap'] = self::posted('website') !== '' || !is_string($_POST['website'] ?? '');

        Session::set(self::DRAFT, $input);
        $this->renderReview($input, []);
    }

    /** Second step: the declaration is made. It is stored, acknowledged and passed on. */
    public function confirm(array $params): void
    {
        $draft = Session::get(self::DRAFT);
        if (!is_array($draft)) {
            // A second click on the button, or the form in another tab: the
            // declaration is already made, or was never begun.
            if (is_array(Session::get(self::DONE))) {
                $this->redirect('/withdrawal/done');
                return;
            }
            Session::flash('error', $this->trans('core.withdrawal.error.expired'));
            $this->redirect('/withdrawal');
            return;
        }

        $app = $this->app;
        $receivedAt = Clock::now();

        if (!$draft['trap'] && self::posted('website') === '') {
            $limiter = new RateLimiter($app->db);
            // Per address as well: whoever fills in this form with someone
            // else's address must not be able to fill that person's mailbox.
            // Both refusals are said openly - a declaration that silently
            // went nowhere would cost its sender the deadline.
            if ($limiter->hit('withdrawal', ClientIp::key(), 5, 3600)
                || $limiter->hit('withdrawal-email', $draft['email'], 3, 3600)) {
                $this->renderReview($draft, [$this->trans('core.withdrawal.error.too_many')]);
                return;
            }

            $this->receive($draft, $receivedAt);
        }

        Session::remove(self::DRAFT);
        Session::set(self::DONE, ['email' => $draft['email'], 'received_at' => $receivedAt]);
        $this->redirect('/withdrawal/done');
    }

    /** The answer after the second step - the same whether or not an order was found. */
    public function done(array $params): void
    {
        $done = Session::get(self::DONE);
        if (!is_array($done)) {
            $this->redirect('/withdrawal');
            return;
        }

        $this->render('withdrawal/done.twig', ['email' => $done['email'], 'received_at' => $done['received_at']]);
    }

    /** Every declaration, for the administration. */
    public function index(array $params): void
    {
        $page = max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));
        $list = $this->app->withdrawals->list($page, self::PER_PAGE);

        $this->render('@admin/withdrawals.twig', [
            'withdrawals' => $list['rows'],
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'waiting' => $this->app->withdrawals->waiting(),
        ]);
    }

    /** An administrator ticks off a declaration that was passed on by hand, or reopens it. */
    public function handled(array $params): void
    {
        $this->app->withdrawals->setHandled((int) $params['id'], self::posted('handled') === '1', $this->app->auth->account()['id']);
        $this->redirect('/admin/withdrawals');
    }

    /** @param array{name: string, email: string, order_number: string, statement: string} $draft */
    private function receive(array $draft, string $receivedAt): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        $account = $app->auth->account();
        $order = $this->matchingOrder($draft['order_number'], $draft['email']);

        $app->withdrawals->create([
            'order_id' => $order['id'] ?? null,
            'order_number' => $draft['order_number'],
            'name' => $draft['name'],
            'email' => $draft['email'],
            'statement' => $draft['statement'],
            'locale' => $locale,
            'account_id' => $account['id'] ?? $order['buyer_id'] ?? null,
        ], $receivedAt);

        // What was typed comes last: a "{link}" in it must stay as typed.
        $data = [
            'received_at' => $receivedAt,
            'number' => $draft['order_number'],
            'email' => $draft['email'],
            'name' => $draft['name'],
            'statement' => $draft['statement'] !== '' ? $draft['statement'] : '–',
        ];

        // The acknowledgement of receipt, with the whole declaration.
        $app->mailer->send($draft['email'], 'emails/withdrawal_receipt.txt.twig', $data);

        if ($order !== null) {
            // On the order page both sides see what was declared and when.
            // A message in the buyer's name does that without a new kind of
            // entry in the order; it says itself where it comes from.
            $app->orders->addMessage($order['id'], $order['buyer_id'], 'buyer', $this->trans('core.withdrawal.order_message', $data));
        }

        $provider = $order !== null && $order['provider_id'] !== null ? $app->providers->find($order['provider_id']) : null;
        if ($provider !== null && $provider['account_status'] === 'active') {
            $to = $app->locales->isEnabled($provider['account_locale']) ? $provider['account_locale'] : $app->locales->default();
            $app->mailer->send($provider['account_email'], 'emails/withdrawal_provider.txt.twig', [
                'title' => $order['offer_title'],
                'link' => $app->url('/orders/' . $order['id'], $to, true),
            ] + $data, $to, $draft['email']);

            return;
        }

        // No order, or no provider left to tell: the platform has to look
        // at it. The same list is under Administration → Withdrawals.
        foreach ($app->accounts->administrators() as $admin) {
            $to = $app->locales->isEnabled($admin['locale']) ? $admin['locale'] : $app->locales->default();
            $app->mailer->send($admin['email'], 'emails/withdrawal_platform.txt.twig', [
                'link' => $app->url('/admin/withdrawals', $to, true),
            ] + $data, $to, $draft['email']);
        }
    }

    /**
     * The order a declaration belongs to - only if the number fits the
     * buyer: the logged-in account, or the address of the buyer's account.
     * A number alone is easy to guess and must not reach anyone's order.
     */
    private function matchingOrder(string $number, string $email): ?array
    {
        $digits = ltrim($number, "# \t");
        if ($digits === '' || !ctype_digit($digits) || strlen($digits) > 9) {
            return null;
        }

        $order = $this->app->orders->find((int) $digits);
        if ($order === null || $order['buyer_id'] === null) {
            return null;
        }
        if (($this->app->auth->account()['id'] ?? null) === $order['buyer_id']) {
            return $order;
        }

        $buyer = $this->app->accounts->findById($order['buyer_id']);

        return $buyer !== null && hash_equals((string) $buyer['email'], $email) ? $order : null;
    }

    private function renderForm(array $input, array $errors): void
    {
        // When the form was shown: see review().
        Session::set(self::FORM_AT, time());

        $account = $this->app->auth->account();
        $orders = $account !== null ? $this->app->orders->list('buyer', $account['id'], null, 1, self::MAX_OWN_ORDERS)['rows'] : [];
        $own = array_column($orders, 'number');
        $chosen = in_array(ltrim($input['order_number'], "# \t"), $own, true) ? ltrim($input['order_number'], "# \t") : '';

        $this->render('withdrawal/form.twig', [
            'name' => $input['name'],
            'email' => $input['email'],
            // Either chosen from the list or typed, never shown twice.
            'order_number' => $chosen === '' ? $input['order_number'] : '',
            'order_choice' => $chosen,
            'statement' => $input['statement'],
            'orders' => array_map(fn (array $order) => [
                'number' => $order['number'],
                'title' => $order['offer_title'],
                'created_at' => $order['created_at'],
            ], $orders),
            'limits' => ['name' => self::MAX_NAME, 'order_number' => self::MAX_ORDER_NUMBER, 'statement' => self::MAX_STATEMENT],
            'errors' => $errors,
        ]);
    }

    private function renderReview(array $draft, array $errors): void
    {
        $this->render('withdrawal/review.twig', [
            'name' => $draft['name'],
            'email' => $draft['email'],
            'order_number' => $draft['order_number'],
            'statement' => $draft['statement'],
            'errors' => $errors,
        ]);
    }

    /** A posted field as text; anything else (an array) counts as empty. */
    private static function posted(string $field): string
    {
        return is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }

    /** One line of text: these values end up in e-mails and in a table. */
    private static function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
