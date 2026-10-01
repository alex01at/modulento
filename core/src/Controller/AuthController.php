<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
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

        $this->render('auth/login.twig');
    }

    public function login(array $params): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $limiter = new RateLimiter($this->app->db);
        if ($limiter->tooManyAttempts('login', $ip, self::MAX_ATTEMPTS * 4, self::WINDOW_SECONDS)
            || $limiter->tooManyAttempts('login-email', $email, self::MAX_ATTEMPTS, self::WINDOW_SECONDS)) {
            Session::flash('error', $this->trans('core.login.too_many_attempts'));
            $this->redirect('/login');
            return;
        }

        $stmt = $this->app->db->prepare("SELECT id, password_hash FROM account WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $account = $stmt->fetch();

        if (!password_verify($password, $account['password_hash'] ?? self::DUMMY_HASH) || !$account) {
            $limiter->recordAttempt('login', $ip);
            $limiter->recordAttempt('login-email', $email);
            Session::flash('error', $this->trans('core.login.failed'));
            $this->redirect('/login');
            return;
        }

        $returnTo = (string) Session::get('login_return_to', '/');
        $this->app->auth->login((int) $account['id']);
        Session::remove('login_return_to');
        $this->app->events->dispatch(new AccountLoggedIn((int) $account['id']));

        // Only a local path - never a full or protocol-relative URL.
        $isLocalPath = str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_contains($returnTo, '\\');
        $this->redirect($isLocalPath ? $returnTo : '/');
    }

    public function logout(array $params): void
    {
        $this->app->auth->logout();
        $this->redirect('/');
    }
}
