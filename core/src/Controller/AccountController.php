<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Account\Tokens;
use Modulento\Core\Event\AccountDeleted;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Support\PasswordPolicy;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;
use PDOException;

/** The logged-in account's own settings. Every route here is login-only. */
final class AccountController extends Controller
{
    private const CHANGE_EMAIL_TTL_SECONDS = 86400;

    public function index(array $params): void
    {
        $this->render('account/index.twig', [
            'locales' => $this->app->locales->enabled(),
            'min_length' => PasswordPolicy::MIN_LENGTH,
            'is_last_admin' => $this->app->accounts->isLastAdmin($this->accountId()),
            'provider_status' => $this->app->providers->findByAccount($this->accountId())['status'] ?? null,
        ]);
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
        $provider = $this->app->providers->findByAccount($this->accountId());
        if ($provider !== null) {
            unset($provider['account_email'], $provider['account_status'], $provider['account_locale'], $provider['decided_by'], $provider['changed_since_decision']);
            $export->add('provider', $provider);
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

        $this->app->accounts->delete($accountId);
        $this->app->events->dispatch(new AccountDeleted($accountId, $email));
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
        $this->redirect('/account');
    }
}
