<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Tokens;
use Modulento\Core\App;
use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class AuthController extends Controller
{
    public const VERIFY_TTL_SECONDS = 172800;

    private const MAX_ATTEMPTS = 5;
    private const MAX_ATTEMPTS_PER_ADDRESS = 50;
    private const WINDOW_SECONDS = 900;
    // Verified against when the e-mail is unknown, so a miss costs the
    // same time as a wrong password and does not reveal which e-mails exist.
    private const DUMMY_HASH = '$2y$10$dahvZsR74OJVWmnBzXm8OuCKPsGLcpoXzRJ0HHV1VirT1dpUji32y';

    public function showLogin(array $params): void
    {
        if ($this->app->auth->check()) {
            $this->redirect('/');
            return;
        }

        $this->render('auth/login.twig', [
            'can_resend_verification' => Session::get('unverified_account_id') !== null,
        ]);
    }

    public function login(array $params): void
    {
        $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // The tight limit counts per address and network address together:
        // wrong passwords typed somewhere else must not lock the owner
        // out. Per address alone there is only a wide limit against
        // guessing from many places at once.
        $pair = $email . '|' . $ip;
        $limiter = new RateLimiter($this->app->db);
        if ($limiter->tooManyAttempts('login', $ip, self::MAX_ATTEMPTS * 4, self::WINDOW_SECONDS)
            || $limiter->tooManyAttempts('login-pair', $pair, self::MAX_ATTEMPTS, self::WINDOW_SECONDS)
            || $limiter->tooManyAttempts('login-email', $email, self::MAX_ATTEMPTS_PER_ADDRESS, 3600)) {
            Session::flash('error', $this->trans('core.login.too_many_attempts'));
            $this->redirect('/login');
            return;
        }

        // Counted before the password is checked, and taken back if it was
        // right: requests sent at the same moment cannot slip past the limit.
        $limiter->recordAttempt('login', $ip);
        $limiter->recordAttempt('login-pair', $pair);
        $limiter->recordAttempt('login-email', $email);

        $account = $this->app->accounts->findByEmail($email);

        if (!password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH)
            || $account === null || $account['status'] !== 'active') {
            Session::flash('error', $this->trans('core.login.failed'));
            $this->redirect('/login');
            return;
        }

        $limiter->release('login', $ip);
        $limiter->release('login-email', $email);
        $limiter->forget('login-pair', $pair);

        // Only reached with the correct password, so saying "not confirmed
        // yet" tells nobody else that the account exists.
        if ($account['email_verified_at'] === null) {
            Session::set('unverified_account_id', (int) $account['id']);
            Session::flash('error', $this->trans('core.login.unverified'));
            $this->redirect('/login');
            return;
        }

        $returnTo = (string) Session::get('login_return_to', '/');
        $this->app->auth->login((int) $account['id']);
        Session::remove('login_return_to');
        Session::remove('unverified_account_id');
        $this->app->events->dispatch(new AccountLoggedIn((int) $account['id']));

        // Only a local path - never a full or protocol-relative URL.
        $isLocalPath = str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_contains($returnTo, '\\');
        // From here on the account's own language, whatever language the
        // login page was opened in.
        $locale = $this->app->locales->isEnabled($account['locale']) ? $account['locale'] : null;
        $this->redirect($isLocalPath ? $returnTo : '/', $locale);
    }

    public function logout(array $params): void
    {
        $this->app->auth->logout();
        $this->redirect('/');
    }

    /**
     * Opening the link only shows a button. Mail scanners and link previews
     * open links too, and must not confirm an address nobody has looked at.
     */
    public function showVerifyEmail(array $params): void
    {
        if ($this->app->tokens->find($params['token'], Tokens::VERIFY_EMAIL) === null) {
            Session::flash('error', $this->trans('core.verify.invalid'));
            $this->redirect('/login');
            return;
        }

        $this->render('auth/verify.twig', ['token' => $params['token']]);
    }

    public function verifyEmail(array $params): void
    {
        $data = $this->app->tokens->consume($params['token'], Tokens::VERIFY_EMAIL);

        if ($data === null) {
            Session::flash('error', $this->trans('core.verify.invalid'));
        } else {
            $this->app->accounts->markVerified($data['account_id']);
            Session::remove('unverified_account_id');
            Session::flash('success', $this->trans('core.verify.done'));
        }

        $this->redirect('/login');
    }

    /** For someone who just tried to log in with the right password but an unconfirmed address. */
    public function resendVerification(array $params): void
    {
        $accountId = Session::get('unverified_account_id');
        $account = $accountId !== null ? $this->app->accounts->findById((int) $accountId) : null;

        if ($account !== null && $account['email_verified_at'] === null
            && !(new RateLimiter($this->app->db))->hit('verify-resend', $account['email'], 3, 3600)) {
            self::sendVerification($this->app, (int) $account['id'], $account['email']);
        }

        Session::flash('success', $this->trans('core.verify.sent'));
        $this->redirect('/login');
    }

    public static function sendVerification(App $app, int $accountId, string $email): void
    {
        $token = $app->tokens->create($accountId, Tokens::VERIFY_EMAIL, self::VERIFY_TTL_SECONDS);

        $app->mailer->send($email, 'emails/verify_email.txt.twig', [
            'link' => $app->url('/verify-email/' . $token, absolute: true),
            'hours' => intdiv(self::VERIFY_TTL_SECONDS, 3600),
        ]);
    }
}
