<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/** Site-wide settings an administrator changes in the browser instead of in .env. */
final class SettingsController extends Controller
{
    /** The tabs of this page; every one is in the same form, so saving keeps all of them. */
    public const TABS = ['general', 'catalogue', 'languages'];

    public function index(array $params): void
    {
        $app = $this->app;

        $this->render('@admin/settings.twig', [
            'tab' => $this->tab(self::TABS),
            'tabs' => self::TABS,
            'settings' => [
                'site_name' => $app->siteName(),
                'mail_from' => $app->settings->get('core.mail_from', $app->config['mail']['from']),
                'registration' => $app->settings->get('core.registration', 'open'),
                'provider_approval' => $app->providers->approvalRequired() ? 'required' : 'off',
                'offer_approval' => $app->offers->approvalRequired() ? 'required' : 'off',
                'currency' => $app->offers->currency(),
                'meta_description' => $app->settings->get('core.meta_description'),
                'poll_seconds' => (int) $app->settings->get('core.poll_seconds', '60'),
                'badge_top_rated_min_average' => $app->badges->topRatedMinAverage(),
                'badge_top_rated_min_count' => $app->badges->topRatedMinCount(),
                'badge_fast_responder_max_minutes' => $app->badges->fastResponderMaxMinutes(),
                'badge_fast_responder_min_sample' => $app->badges->fastResponderMinSample(),
            ],
            'available_locales' => $app->locales->available(),
            'enabled_locales' => $app->locales->enabled(),
            'default_locale' => $app->locales->default(),
        ]);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $mailFrom = trim((string) ($_POST['mail_from'] ?? ''));
        $default = (string) ($_POST['default_locale'] ?? '');
        $enabled = array_map('strval', is_array($_POST['locales'] ?? null) ? $_POST['locales'] : []);

        if ($siteName === '' || mb_strlen($siteName) > 100
            || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false
            || !in_array($default, $app->locales->available(), true)) {
            Session::flash('error', $this->trans('core.admin.settings.invalid'));
            $this->redirect('/admin/settings?tab=' . $this->tab(self::TABS));
            return;
        }

        $app->settings->set('core.site_name', $siteName);
        $app->settings->set('core.mail_from', $mailFrom);
        $app->settings->set('core.registration', ($_POST['registration'] ?? '') === 'closed' ? 'closed' : 'open');
        // Shown on pages that have no description of their own (an offer's
        // summary, a blog post's), and used for og:description.
        $app->settings->set('core.meta_description', mb_substr(trim((string) ($_POST['meta_description'] ?? '')), 0, 300));
        // Seconds between two asks for new messages; 0 switches the asking off.
        $app->settings->set('core.poll_seconds', (string) max(0, min(3600, (int) ($_POST['poll_seconds'] ?? 0))));
        $app->locales->save($default, $enabled);

        $approvalRequired = ($_POST['provider_approval'] ?? '') !== 'off';
        if ($approvalRequired !== $app->providers->approvalRequired()) {
            // Switching approval off approves everyone who was waiting.
            foreach ($app->providers->setApprovalRequired($approvalRequired) as $providerId) {
                $provider = $app->providers->find($providerId);
                AdminProviderController::announce($app, ['status' => 'pending'] + $provider, 'approved');
            }
        }

        $offerApprovalRequired = ($_POST['offer_approval'] ?? '') !== 'off';
        if ($offerApprovalRequired !== $app->offers->approvalRequired()) {
            foreach ($app->offers->setApprovalRequired($offerApprovalRequired) as $offerId) {
                AdminCatalogueController::announce($app, ['status' => 'pending'] + $app->offers->find($offerId), 'published');
            }
        }

        // Three letters, as in ISO 4217. Existing offers keep the currency
        // they were created in.
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $app->settings->set('core.currency', $currency);
        }

        $app->settings->set('core.badge.top_rated.min_average', (string) max(1, min(5, (float) str_replace(',', '.', (string) ($_POST['badge_top_rated_min_average'] ?? '4.5')))));
        $app->settings->set('core.badge.top_rated.min_count', (string) max(1, min(1000, (int) ($_POST['badge_top_rated_min_count'] ?? 5))));
        $app->settings->set('core.badge.fast_responder.max_minutes', (string) max(1, min(100000, (int) ($_POST['badge_fast_responder_max_minutes'] ?? 120))));
        $app->settings->set('core.badge.fast_responder.min_sample', (string) max(1, min(1000, (int) ($_POST['badge_fast_responder_min_sample'] ?? 5))));

        // The administrator's own language may just have been switched off,
        // or the default - and with it every address - may have changed.
        $locale = $app->locales->isEnabled($app->translator->locale()) ? $app->translator->locale() : $app->locales->default();
        $app->translator->setLocale($locale);

        Session::flash('success', $this->trans('core.admin.settings.saved'));
        $this->redirect('/admin/settings?tab=' . $this->tab(self::TABS));
    }
}
