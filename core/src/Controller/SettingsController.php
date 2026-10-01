<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/** Site-wide settings an administrator changes in the browser instead of in .env. */
final class SettingsController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;

        $this->render('@admin/settings.twig', [
            'settings' => [
                'site_name' => $app->siteName(),
                'mail_from' => $app->settings->get('core.mail_from', $app->config['mail']['from']),
                'registration' => $app->settings->get('core.registration', 'open'),
                'provider_approval' => $app->providers->approvalRequired() ? 'required' : 'off',
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
            $this->redirect('/admin/settings');
            return;
        }

        $app->settings->set('core.site_name', $siteName);
        $app->settings->set('core.mail_from', $mailFrom);
        $app->settings->set('core.registration', ($_POST['registration'] ?? '') === 'closed' ? 'closed' : 'open');
        $app->locales->save($default, $enabled);

        $approvalRequired = ($_POST['provider_approval'] ?? '') !== 'off';
        if ($approvalRequired !== $app->providers->approvalRequired()) {
            // Switching approval off approves everyone who was waiting.
            foreach ($app->providers->setApprovalRequired($approvalRequired) as $providerId) {
                $provider = $app->providers->find($providerId);
                AdminProviderController::announce($app, ['status' => 'pending'] + $provider, 'approved');
            }
        }

        // The administrator's own language may just have been switched off,
        // or the default - and with it every address - may have changed.
        $locale = $app->locales->isEnabled($app->translator->locale()) ? $app->translator->locale() : $app->locales->default();
        $app->translator->setLocale($locale);

        Session::flash('success', $this->trans('core.admin.settings.saved'));
        $this->redirect('/admin/settings');
    }
}
