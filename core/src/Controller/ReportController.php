<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Report\Reports;
use Modulento\Core\Support\ClientIp;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

/**
 * The report form: anyone can tell the platform about content they
 * consider illegal, gets a confirmation of receipt, and later the decision
 * with its reasons. An administrator decides; what is done about the
 * content happens where that content is administered.
 */
final class ReportController extends Controller
{
    private const MIN_FILL_SECONDS = 2;
    private const PER_PAGE = 30;

    private const MAX_URL = 500;
    private const MAX_NAME = 150;
    private const MAX_EMAIL = 255;
    private const MIN_EXPLANATION = 20;
    private const MAX_EXPLANATION = 5000;
    private const MAX_NOTE = 2000;

    private const FORM_AT = 'report_form_at';
    private const DONE = 'report_done';

    public function form(array $params): void
    {
        $account = $this->app->auth->account();

        $this->renderForm([
            // A link on an offer or a provider page brings its address along.
            'url' => is_string($_GET['url'] ?? null) ? mb_substr(self::line($_GET['url']), 0, self::MAX_URL) : '',
            'category' => '',
            'explanation' => '',
            'name' => (string) ($account['display_name'] ?? ''),
            'email' => (string) ($account['email'] ?? ''),
        ], []);
    }

    public function send(array $params): void
    {
        $app = $this->app;
        $input = [
            'url' => self::line(self::posted('url')),
            'category' => self::posted('category'),
            'explanation' => trim(str_replace("\r\n", "\n", self::posted('explanation'))),
            'name' => self::line(self::posted('name')),
            'email' => Accounts::normalizeEmail(self::posted('email')),
        ];
        $errors = [];

        if ($input['url'] === '' || mb_strlen($input['url']) > self::MAX_URL) {
            $errors[] = $this->trans('core.report.error.url', ['max' => self::MAX_URL]);
        }
        if (!in_array($input['category'], Reports::CATEGORIES, true)) {
            $errors[] = $this->trans('core.report.error.category');
        }
        if (mb_strlen($input['explanation']) < self::MIN_EXPLANATION || mb_strlen($input['explanation']) > self::MAX_EXPLANATION) {
            $errors[] = $this->trans('core.report.error.explanation', ['min' => self::MIN_EXPLANATION, 'max' => self::MAX_EXPLANATION]);
        }
        if ($input['name'] === '' || mb_strlen($input['name']) > self::MAX_NAME) {
            $errors[] = $this->trans('core.report.error.name', ['max' => self::MAX_NAME]);
        }
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false || strlen($input['email']) > self::MAX_EMAIL) {
            $errors[] = $this->trans('core.register.error.email');
        }
        // The sender states that the notice is accurate to the best of
        // their knowledge; without that it is not a notice to act on.
        if (self::posted('good_faith') !== '1') {
            $errors[] = $this->trans('core.report.error.good_faith');
        }
        // A form sent back within a moment of being shown was not filled in
        // by a person. A person who really was that fast just sends it again.
        $shownAt = Session::get(self::FORM_AT);
        if ($errors === [] && ($shownAt === null || time() - (int) $shownAt < self::MIN_FILL_SECONDS)) {
            $errors[] = $this->trans('core.register.error.too_fast');
        }
        if ($errors === [] && self::posted('website') === '' && is_string($_POST['website'] ?? '')) {
            $limiter = new RateLimiter($app->db);
            // Per address as well: the confirmation must not become a way
            // to fill someone else's mailbox.
            if ($limiter->hit('report', ClientIp::key(), 5, 3600) || $limiter->hit('report-email', $input['email'], 5, 3600)) {
                $errors[] = $this->trans('core.error.too_many_requests');
            }
        }

        if ($errors !== []) {
            $this->renderForm($input, $errors);
            return;
        }

        $receivedAt = Clock::now();

        // A field no person sees or fills in. A bot that does gets the same
        // answer as everyone else; nothing is stored or sent.
        if (self::posted('website') === '' && is_string($_POST['website'] ?? '')) {
            $this->receive($input, $receivedAt);
        }

        Session::set(self::DONE, ['email' => $input['email'], 'received_at' => $receivedAt]);
        $this->redirect('/report/done');
    }

    public function done(array $params): void
    {
        $done = Session::get(self::DONE);
        if (!is_array($done)) {
            $this->redirect('/report');
            return;
        }

        $this->render('report/done.twig', ['email' => $done['email'], 'received_at' => $done['received_at']]);
    }

    /** Every notice, open ones first, for the administration. */
    public function index(array $params): void
    {
        $page = max(1, (int) (is_string($_GET['page'] ?? null) ? $_GET['page'] : 1));
        $list = $this->app->reports->list($page, self::PER_PAGE);

        $this->render('@admin/reports.twig', [
            'reports' => $list['rows'],
            'open' => $this->app->reports->openCount(),
            'decisions' => Reports::DECISIONS,
            'max_note' => self::MAX_NOTE,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
        ]);
    }

    /** An administrator decides a notice; the sender is told the decision and its reasons. */
    public function decide(array $params): void
    {
        $app = $this->app;
        $report = $app->reports->find((int) $params['id']);
        $decision = self::posted('decision');
        $note = trim(str_replace("\r\n", "\n", self::posted('note')));

        if ($report === null || !in_array($decision, Reports::DECISIONS, true)) {
            $this->redirect('/admin/reports');
            return;
        }
        // The sender has a right to the reasons, so there have to be some.
        if ($note === '' || mb_strlen($note) > self::MAX_NOTE) {
            Session::flash('error', $this->trans('core.admin.reports.error.note', ['max' => self::MAX_NOTE]));
            $this->redirect('/admin/reports');
            return;
        }

        if ($app->reports->decide((int) $report['id'], $decision, $note, $app->auth->account()['id'])) {
            $locale = $app->locales->isEnabled($report['locale']) ? $report['locale'] : $app->locales->default();
            $app->mailer->send($report['email'], 'emails/report_decision.txt.twig', [
                'reference' => (int) $report['id'],
                'url' => $report['url'],
                'decision' => $app->translator->trans('core.report.decision.' . $decision, [], $locale),
                'note' => $note,
            ], $locale);

            // Action against content is a restriction of the provider it
            // belongs to, who is owed the reasons as well - without
            // learning who sent the notice.
            $provider = $decision === 'actioned' && $report['provider_id'] !== null ? $app->providers->find((int) $report['provider_id']) : null;
            if ($provider !== null && $provider['account_status'] === 'active') {
                $to = $app->locales->isEnabled($provider['account_locale']) ? $provider['account_locale'] : $app->locales->default();
                $app->mailer->send($provider['account_email'], 'emails/report_provider.txt.twig', ['url' => $report['url'], 'note' => $note], $to);
            }
            Session::flash('success', $this->trans('core.admin.reports.decided'));
        }

        $this->redirect('/admin/reports');
    }

    /** @param array{url: string, category: string, explanation: string, name: string, email: string} $input */
    private function receive(array $input, string $receivedAt): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();

        $id = $app->reports->create($input + ['locale' => $locale, 'account_id' => $app->auth->account()['id'] ?? null] + $this->subject($input['url']), $receivedAt);

        // What was typed comes last: a "{link}" in it must stay as typed.
        $data = fn (string $in) => [
            'reference' => $id,
            'received_at' => $receivedAt,
            'category' => $app->translator->trans('core.report.category.' . $input['category'], [], $in),
            'url' => $input['url'],
            'name' => $input['name'],
            'email' => $input['email'],
            'explanation' => $input['explanation'],
        ];

        $app->mailer->send($input['email'], 'emails/report_receipt.txt.twig', $data($locale));

        foreach ($app->accounts->administrators() as $admin) {
            $to = $app->locales->isEnabled($admin['locale']) ? $admin['locale'] : $app->locales->default();
            $app->mailer->send($admin['email'], 'emails/report_platform.txt.twig', ['link' => $app->url('/admin/reports', $to, true)] + $data($to), $to);
        }
    }

    /**
     * The offer or provider of this site an address leads to, if any.
     *
     * @return array{offer_id: ?int, provider_id: ?int}
     */
    private function subject(string $url): array
    {
        $app = $this->app;
        $none = ['offer_id' => null, 'provider_id' => null];

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || ($host !== null && $host !== parse_url($app->config['app']['url'], PHP_URL_HOST))) {
            return $none;
        }

        $split = $app->locales->split($path);
        if (preg_match('#^/offers/([^/]+)/?$#', $split['path'], $match) === 1) {
            $offer = $app->offers->findPublicBySlug($split['locale'], rawurldecode($match[1]));

            return $offer !== null ? ['offer_id' => $offer['id'], 'provider_id' => $offer['provider_id']] : $none;
        }
        if (preg_match('#^/providers/([^/]+)/?$#', $split['path'], $match) === 1) {
            $provider = $app->providers->findPublicBySlug(rawurldecode($match[1]));

            return $provider !== null ? ['offer_id' => null, 'provider_id' => (int) $provider['id']] : $none;
        }

        return $none;
    }

    private function renderForm(array $input, array $errors): void
    {
        // When the form was shown: see send().
        Session::set(self::FORM_AT, time());

        $this->render('report/form.twig', $input + [
            'categories' => Reports::CATEGORIES,
            'limits' => ['url' => self::MAX_URL, 'name' => self::MAX_NAME, 'min' => self::MIN_EXPLANATION, 'max' => self::MAX_EXPLANATION],
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
