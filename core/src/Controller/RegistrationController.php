<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Account\Accounts;
use Modulento\Core\Account\Tokens;
use Modulento\Core\Event\AccountRegistered;
use Modulento\Core\Support\PasswordPolicy;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class RegistrationController extends Controller
{
    private const RESET_TTL_SECONDS = 3600;

    public function showRegister(array $params): void
    {
        if ($this->app->auth->check()) {
            $this->redirect('/');
            return;
        }

        $this->render('auth/register.twig', ['errors' => [], 'email' => '', 'min_length' => PasswordPolicy::MIN_LENGTH]);
    }

    public function register(array $params): void
    {
        $email = Accounts::normalizeEmail((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            $errors[] = $this->trans('core.register.error.email');
        }
        $problem = PasswordPolicy::problem($password);
        if ($problem !== null) {
            $errors[] = $this->trans($problem, ['min' => PasswordPolicy::MIN_LENGTH]);
        } elseif ($password !== (string) ($_POST['password_repeat'] ?? '')) {
            $errors[] = $this->trans('core.password.mismatch');
        }
        if ($errors === [] && (new RateLimiter($this->app->db))->hit('register', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 10, 3600)) {
            $errors[] = $this->trans('core.error.too_many_requests');
        }

        if ($errors !== []) {
            $this->render('auth/register.twig', ['errors' => $errors, 'email' => $email, 'min_length' => PasswordPolicy::MIN_LENGTH]);
            return;
        }

        // A field no person sees or fills in. A bot that does gets the same
        // answer as everyone else and no account.
        if ((string) ($_POST['website'] ?? '') === '') {
            $this->createOrNotify($email, $password);
        }

        // The same answer whether or not the address was known, so this
        // form cannot be used to find out who has an account.
        Session::flash('success', $this->trans('core.register.done'));
        $this->redirect('/login');
    }

    private function createOrNotify(string $email, string $password): void
    {
        $existing = $this->app->accounts->findByEmail($email);

        if ($existing === null) {
            $accountId = $this->app->accounts->create($email, $password, $this->app->translator->locale(), verified: false);
            AuthController::sendVerification($this->app, $accountId, $email);
            $this->app->events->dispatch(new AccountRegistered($accountId));
            return;
        }

        if ($existing['status'] !== 'active') {
            return;
        }

        if ($existing['email_verified_at'] === null) {
            AuthController::sendVerification($this->app, (int) $existing['id'], $email);
        } else {
            $this->app->mailer->send($email, 'emails/already_registered.txt.twig', [
                'login_link' => $this->app->config['app']['url'] . '/login',
                'reset_link' => $this->app->config['app']['url'] . '/forgot-password',
            ]);
        }
    }

    public function showForgot(array $params): void
    {
        $this->render('auth/forgot.twig');
    }

    public function forgot(array $params): void
    {
        $email = Accounts::normalizeEmail((string) ($_POST['email'] ?? ''));
        $limiter = new RateLimiter($this->app->db);

        $limited = $limiter->hit('forgot', $_SERVER['REMOTE_ADDR'] ?? 'unknown', 5, 900)
            || $limiter->hit('forgot-email', $email, 3, 3600);
        $account = $limited ? null : $this->app->accounts->findByEmail($email);

        if ($account !== null && $account['status'] === 'active') {
            $token = $this->app->tokens->create((int) $account['id'], Tokens::RESET_PASSWORD, self::RESET_TTL_SECONDS);
            $this->app->mailer->send($account['email'], 'emails/reset_password.txt.twig', [
                'link' => $this->app->config['app']['url'] . '/reset-password/' . $token,
                'minutes' => intdiv(self::RESET_TTL_SECONDS, 60),
            ]);
        }

        Session::flash('success', $this->trans('core.forgot.done'));
        $this->redirect('/login');
    }

    public function showReset(array $params): void
    {
        if ($this->app->tokens->find($params['token'], Tokens::RESET_PASSWORD) === null) {
            Session::flash('error', $this->trans('core.reset.invalid'));
            $this->redirect('/forgot-password');
            return;
        }

        $this->render('auth/reset.twig', ['errors' => [], 'token' => $params['token'], 'min_length' => PasswordPolicy::MIN_LENGTH]);
    }

    public function reset(array $params): void
    {
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];

        $problem = PasswordPolicy::problem($password);
        if ($problem !== null) {
            $errors[] = $this->trans($problem, ['min' => PasswordPolicy::MIN_LENGTH]);
        } elseif ($password !== (string) ($_POST['password_repeat'] ?? '')) {
            $errors[] = $this->trans('core.password.mismatch');
        }

        if ($errors !== []) {
            $this->render('auth/reset.twig', ['errors' => $errors, 'token' => $params['token'], 'min_length' => PasswordPolicy::MIN_LENGTH]);
            return;
        }

        $data = $this->app->tokens->consume($params['token'], Tokens::RESET_PASSWORD);
        if ($data === null) {
            Session::flash('error', $this->trans('core.reset.invalid'));
            $this->redirect('/forgot-password');
            return;
        }

        // Changing the hash also ends every session that is still logged
        // in with the old password (see Auth::account()). Whoever opened
        // the link has shown that the address is theirs.
        $this->app->accounts->setPassword($data['account_id'], $password);
        $this->app->accounts->markVerified($data['account_id']);

        Session::flash('success', $this->trans('core.reset.done'));
        $this->redirect('/login');
    }
}
