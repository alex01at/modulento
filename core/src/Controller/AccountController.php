<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Account\Avatars;
use Modulento\Core\Account\LoginTokens;
use Modulento\Core\Account\Preferences;
use Modulento\Core\Catalogue\OfferView;
use Modulento\Core\Account\Tokens;
use Modulento\Core\Account\AccountRemoval;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Review\Reviews;
use Modulento\Core\Support\PasswordPolicy;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;
use PDOException;

/** The logged-in account's overview and its own settings. Every route here is login-only. */
final class AccountController extends Controller
{
    private const CHANGE_EMAIL_TTL_SECONDS = 86400;
    private const RECENT = 5;
    private const DEVICE_NAME_LENGTH = 80;

    /** Where someone lands after "Dashboard": what is going on, and the ways onward. */
    public function dashboard(array $params): void
    {
        $app = $this->app;
        $account = $app->auth->account();
        $provider = $app->providers->findByAccount($account['id']);
        $providerId = $provider !== null ? (int) $provider['id'] : null;

        $rows = fn (string $role, int $id) => array_map(fn (array $order) => [
            'id' => $order['id'],
            'number' => $order['number'],
            'title' => $order['offer_title'],
            'state_label_key' => $app->orders->flow($order['flow'])?->states()[$order['state']]['label'] ?? 'core.order.state_unknown',
            'total' => $order['total'],
            'currency' => $order['currency'],
        ], $app->orders->list($role, $id, null, 1, self::RECENT)['rows']);

        $this->render('account/dashboard.twig', [
            'name' => ($account['display_name'] ?? '') !== '' ? $account['display_name'] : strstr($account['email'], '@', true),
            'avatar' => $app->avatars->url($account['id']),
            'recent' => $this->recentlyViewed(),
            'purchases' => $app->orders->tally('buyer', $account['id']) + ['recent' => $rows('buyer', $account['id'])],
            'provider' => $provider !== null ? [
                'name' => $provider['name'],
                'status' => $provider['status'],
                'path' => $provider['status'] === 'approved' ? '/providers/' . $provider['slug'] : null,
                'rating' => Reviews::summary($provider),
                'offers' => $app->offers->listAll($providerId, null, 1, 1)['total'],
                'offers_public' => $app->offers->listAll($providerId, 'published', 1, 1)['total'],
                'sales' => $app->orders->tally('provider', $providerId) + ['recent' => $rows('provider', $providerId)],
                'types' => array_map(fn ($type) => ['id' => $type->id(), 'label_key' => $type->labelKey()], array_values($app->offers->types())),
            ] : null,
        ]);
    }

    /**
     * The offers this visitor looked at last, as far as they are still
     * public. The list lives in the session: nothing is recorded about who
     * looked at what.
     */
    private function recentlyViewed(): array
    {
        $app = $this->app;
        $offers = [];
        foreach (array_slice((array) Session::get('recent_offers', []), 0, 4) as $id) {
            $offer = is_int($id) ? $app->offers->find($id) : null;
            if ($offer !== null && $app->offers->isPublic($offer)) {
                $offers[] = $offer;
            }
        }

        return OfferView::cards($offers, $app);
    }

    public function setAvatar(array $params): void
    {
        $problem = (new RateLimiter($this->app->db))->hit('avatar', (string) $this->accountId(), 20, 3600)
            ? 'core.error.too_many_requests'
            : $this->app->avatars->set($this->accountId(), is_array($_FILES['avatar'] ?? null) ? $_FILES['avatar'] : []);

        $this->back($problem === null ? 'success' : 'error', $problem ?? 'core.account.avatar.saved', [
            'megabytes' => intdiv(Avatars::MAX_BYTES, 1024 * 1024),
        ]);
    }

    public function deleteAvatar(array $params): void
    {
        $this->app->avatars->delete($this->accountId());
        $this->back('success', 'core.account.avatar.deleted');
    }

    public function index(array $params): void
    {
        $this->render('account/index.twig', [
            'avatar' => $this->app->avatars->url($this->accountId()),
            'locales' => $this->app->locales->enabled(),
            'color_schemes' => Preferences::COLOR_SCHEMES,
            'min_length' => PasswordPolicy::MIN_LENGTH,
            'is_last_admin' => $this->app->accounts->isLastAdmin($this->accountId()),
            'provider_status' => $this->app->providers->findByAccount($this->accountId())['status'] ?? null,
            'devices' => array_map(fn (array $device) => [
                'created_at' => $device['created_at'],
                'last_used_at' => $device['last_used_at'],
                'browser' => $device['user_agent'] !== null ? mb_strimwidth($device['user_agent'], 0, self::DEVICE_NAME_LENGTH, '…') : null,
                'current' => $device['current'],
            ], $this->app->loginTokens->devices($this->accountId())),
            'remember_days' => intdiv(LoginTokens::TTL_SECONDS, 86400),
        ]);
    }

    /**
     * "Log out everywhere": no device is remembered any more, this one
     * included. Asks for no password - it only takes access away.
     */
    public function revokeSessions(array $params): void
    {
        $this->app->loginTokens->revokeAll($this->accountId());
        $this->app->loginTokens->forget();
        $this->back('success', 'core.account.devices.revoked');
    }

    public function updateProfile(array $params): void
    {
        $name = trim((string) ($_POST['display_name'] ?? ''));
        $locale = (string) ($_POST['locale'] ?? '');

        if (mb_strlen($name) > 100 || !$this->app->locales->isEnabled($locale)) {
            $this->back('error', 'core.account.profile.invalid');
            return;
        }

        $this->app->accounts->updateProfile($this->accountId(), $name !== '' ? $name : null, $locale);
        // Answer in the language just chosen.
        $this->app->translator->setLocale($locale);
        $this->back('success', 'core.account.profile.saved');
    }

    public function updateAppearance(array $params): void
    {
        $scheme = $_POST['color_scheme'] ?? null;

        if (!is_string($scheme) || !in_array($scheme, Preferences::COLOR_SCHEMES, true)) {
            $this->back('error', 'core.account.appearance.invalid');
            return;
        }

        // "auto" is the default and needs no row.
        $this->app->preferences->set($this->accountId(), Preferences::COLOR_SCHEME, $scheme !== 'auto' ? $scheme : null);
        $this->back('success', 'core.account.appearance.saved');
    }

    public function changePassword(array $params): void
    {
        if (!$this->passwordConfirmed()) {
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $problem = PasswordPolicy::problem($password);

        if ($problem !== null) {
            $this->back('error', $problem, ['min' => PasswordPolicy::MIN_LENGTH]);
            return;
        }
        if ($password !== (string) ($_POST['password_repeat'] ?? '')) {
            $this->back('error', 'core.password.mismatch');
            return;
        }

        $account = $this->app->auth->account();
        $this->app->accounts->setPassword($this->accountId(), $password);
        $this->app->tokens->revoke($this->accountId(), Tokens::RESET_PASSWORD);
        $this->app->auth->refreshStamp();
        $this->app->loginTokens->renew($this->accountId());
        // If it was not the owner, this mail is how they find out.
        $this->app->mailer->send($account['email'], 'emails/password_changed.txt.twig', [
            'reset_link' => $this->app->url('/forgot-password', absolute: true),
        ]);

        $this->back('success', 'core.account.password.saved');
    }

    public function changeEmail(array $params): void
    {
        if (!$this->passwordConfirmed()) {
            return;
        }

        $email = Accounts::normalizeEmail((string) ($_POST['email'] ?? ''));
        $account = $this->app->auth->account();

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255 || $email === $account['email']) {
            $this->back('error', 'core.account.email.invalid');
            return;
        }
        if ((new RateLimiter($this->app->db))->hit('change-email', (string) $this->accountId(), 3, 3600)) {
            $this->back('error', 'core.error.too_many_requests');
            return;
        }

        // The address only changes once the link sent to it was opened. If
        // it belongs to another account, nothing is sent and the answer
        // is the same.
        if ($this->app->accounts->findByEmail($email) === null) {
            $token = $this->app->tokens->create($this->accountId(), Tokens::CHANGE_EMAIL, self::CHANGE_EMAIL_TTL_SECONDS, $email);
            $this->app->mailer->send($email, 'emails/change_email.txt.twig', [
                'link' => $this->app->url('/account/confirm-email/' . $token, absolute: true),
                'hours' => intdiv(self::CHANGE_EMAIL_TTL_SECONDS, 3600),
            ]);
        }

        $this->back('success', 'core.account.email.sent', ['email' => $email]);
    }

    public function confirmEmail(array $params): void
    {
        $data = $this->app->tokens->consume($params['token'], Tokens::CHANGE_EMAIL);

        // The link is opened while logged in to the account it was made for.
        if ($data === null || $data['account_id'] !== $this->accountId() || $data['payload'] === null) {
            $this->back('error', 'core.account.email.link_invalid');
            return;
        }

        try {
            $this->app->accounts->setEmail($this->accountId(), $data['payload']);
        } catch (PDOException) {
            // Someone else registered the address in the meantime.
            $this->back('error', 'core.account.email.link_invalid');
            return;
        }

        // Whoever was remembered under the old address logs in again.
        $this->app->loginTokens->renew($this->accountId());
        $this->back('success', 'core.account.email.saved');
    }

    public function export(array $params): void
    {
        $row = $this->app->accounts->findById($this->accountId());
        $export = new AccountExport($this->accountId());
        $export->add('core', [
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'locale' => $row['locale'],
            'created_at' => $row['created_at'],
            'email_verified_at' => $row['email_verified_at'],
            'last_login_at' => $row['last_login_at'],
        ]);
        $preferences = $this->app->preferences->all($this->accountId());
        if ($preferences !== []) {
            $export->add('preferences', $preferences);
        }
        $provider = $this->app->providers->findByAccount($this->accountId());
        if ($provider !== null) {
            unset($provider['account_email'], $provider['account_status'], $provider['account_locale'], $provider['decided_by'], $provider['changed_since_decision']);
            $export->add('provider', $provider);
        }
        $orders = $this->app->orders->list('buyer', $this->accountId(), null, 1, 100000)['rows'];
        if ($orders !== []) {
            $export->add('orders', array_map(fn (array $order) => array_intersect_key($order, array_flip(
                ['number', 'offer_title', 'provider_name', 'state', 'total', 'currency', 'payment_state', 'created_at', 'closed_at']
            )), $orders));
        }
        $reviews = $this->app->reviews->byAuthor($this->accountId());
        if ($reviews !== []) {
            $export->add('reviews', $reviews);
        }
        $withdrawals = $this->app->withdrawals->byAccount($this->accountId());
        if ($withdrawals !== []) {
            $export->add('withdrawals', $withdrawals);
        }
        $devices = $this->app->loginTokens->devices($this->accountId());
        if ($devices !== []) {
            $export->add('remembered_devices', array_map(fn (array $device) => array_intersect_key($device, array_flip(
                ['created_at', 'last_used_at', 'expires_at', 'user_agent']
            )), $devices));
        }
        $reports = $this->app->reports->byAccount($this->accountId());
        if ($reports !== []) {
            $export->add('reports', $reports);
        }
        $this->app->events->dispatch($export);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="account-export.json"');
        header('Cache-Control: no-store');
        echo json_encode($export->sections(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function delete(array $params): void
    {
        if (!$this->passwordConfirmed()) {
            return;
        }
        if ($this->app->accounts->isLastAdmin($this->accountId())) {
            $this->back('error', 'core.account.delete.last_admin');
            return;
        }

        $accountId = $this->accountId();
        $email = $this->app->auth->account()['email'];
        $provider = $this->app->providers->findByAccount($accountId);

        // The other side of an unfinished order must not be left standing.
        if ($this->app->orders->hasOpen($accountId, $provider !== null ? (int) $provider['id'] : null)) {
            $this->back('error', 'core.account.delete.open_orders');
            return;
        }

        AccountRemoval::run($this->app, $accountId, $email);
        // The tokens went with the account; the cookie is still in the browser.
        $this->app->loginTokens->forget();
        $this->app->auth->logout();
        $this->redirect('/');
    }

    private function accountId(): int
    {
        return $this->app->auth->account()['id'];
    }

    /** Sensitive changes ask for the current password again; a session left open is not enough. */
    private function passwordConfirmed(): bool
    {
        $row = $this->app->accounts->findById($this->accountId());

        if ((new RateLimiter($this->app->db))->tooManyAttempts('confirm-password', (string) $this->accountId(), 5, 900)) {
            $this->back('error', 'core.error.too_many_requests');
            return false;
        }
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $row['password_hash'])) {
            (new RateLimiter($this->app->db))->recordAttempt('confirm-password', (string) $this->accountId());
            $this->back('error', 'core.account.wrong_password');
            return false;
        }

        return true;
    }

    private function back(string $type, string $messageKey, array $replacements = []): void
    {
        Session::flash($type, $this->trans($messageKey, $replacements));
        $this->redirect('/account/settings');
    }
}
