<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Payment\BankAccount;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;
use RuntimeException;

/**
 * A provider sets up how buyers pay: bank details, the own PayPal app, the
 * own Stripe account. Every route here is login-only and needs a provider
 * profile.
 */
final class PaymentSettingsController extends Controller
{
    private const PATH = '/account/payments';
    /** The last segment of the addresses here, and the method it stands for. */
    private const METHODS = ['transfer' => Payments::TRANSFER, 'paypal' => Payments::PAYPAL, 'stripe' => Payments::STRIPE];

    public function index(array $params): void
    {
        $providerId = $this->providerId();
        if ($providerId !== null) {
            $this->renderPage($providerId, [], []);
        }
    }

    public function saveTransfer(array $params): void
    {
        $providerId = $this->providerId(Payments::TRANSFER);
        if ($providerId === null) {
            return;
        }

        $text = fn (string $field) => trim(is_string($_POST[$field] ?? null) ? $_POST[$field] : '');
        $details = [
            'holder' => $text('holder'),
            'iban' => BankAccount::normalize($text('iban')),
            'bic' => BankAccount::normalize($text('bic')),
            'bank' => $text('bank'),
        ];

        $errors = [];
        if ($details['holder'] === '' || mb_strlen($details['holder']) > 100) {
            $errors[] = 'core.payment.transfer.error.holder';
        }
        if (!BankAccount::isIban($details['iban'])) {
            $errors[] = 'core.payment.transfer.error.iban';
        }
        if ($details['bic'] !== '' && !BankAccount::isBic($details['bic'])) {
            $errors[] = 'core.payment.transfer.error.bic';
        }
        if (mb_strlen($details['bank']) > 100) {
            $errors[] = 'core.payment.transfer.error.bank';
        }

        if ($errors !== []) {
            // What was typed, so that a slip in a long number can be corrected.
            $this->renderPage($providerId, array_map(fn (string $key) => $this->trans($key), $errors), ['iban' => $text('iban'), 'bic' => $text('bic')] + $details);
            return;
        }

        $this->app->payments->saveTransfer($providerId, $details);
        Session::flash('success', $this->trans('core.payment.transfer.saved'));
        $this->redirect(self::PATH);
    }

    public function savePaypal(array $params): void
    {
        $providerId = $this->providerId(Payments::PAYPAL);
        if ($providerId === null) {
            return;
        }

        $payments = $this->app->payments;
        $clientId = trim(is_string($_POST['client_id'] ?? null) ? $_POST['client_id'] : '');
        $secret = trim(is_string($_POST['secret'] ?? null) ? $_POST['secret'] : '');
        $hasSecret = ($payments->config($providerId, Payments::PAYPAL)['data']['secret'] ?? '') !== '';

        // Printable characters without spaces: what was pasted, not a sentence.
        $plausible = fn (string $value) => preg_match('/^[\x21-\x7E]{1,255}$/', $value) === 1;
        if (!$plausible($clientId) || ($secret === '' ? !$hasSecret : !$plausible($secret))) {
            Session::flash('error', $this->trans('core.payment.paypal.error.incomplete'));
            $this->redirect(self::PATH);
            return;
        }

        try {
            // An empty field keeps the stored secret: it is never shown again.
            $payments->savePaypal($providerId, $clientId, $secret !== '' ? $secret : null, isset($_POST['sandbox']));
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
            $this->redirect(self::PATH);
            return;
        }

        $this->verifyPaypal($providerId);
    }

    /** "Check connection": asks PayPal for a token with the stored credentials. */
    public function checkPaypal(array $params): void
    {
        $providerId = $this->providerId(Payments::PAYPAL);
        if ($providerId !== null) {
            $this->verifyPaypal($providerId);
        }
    }

    private function verifyPaypal(int $providerId): void
    {
        if ((new RateLimiter($this->app->db))->hit('paypal-check', (string) $this->app->auth->account()['id'], 10, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
            $this->redirect(self::PATH);
            return;
        }

        try {
            $this->app->payments->checkPaypal($providerId);
            Session::flash('success', $this->trans('core.payment.paypal.verified'));
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans('core.payment.paypal.error.check', ['reason' => $this->trans(Payments::report($e))]));
        }

        $this->redirect(self::PATH);
    }

    /**
     * "Connect with Stripe": creates the provider's connected account if
     * there is none and leads to Stripe's own pages to complete it.
     */
    public function connectStripe(array $params): void
    {
        $providerId = $this->providerId(Payments::STRIPE);
        if ($providerId !== null) {
            $this->toStripe($providerId);
        }
    }

    /**
     * Where Stripe sends the provider if its link has expired or was used
     * before. Following a link must not create anything, so this only goes
     * on for an account the button has created.
     */
    public function resumeStripe(array $params): void
    {
        $providerId = $this->providerId(Payments::STRIPE);
        if ($providerId === null) {
            return;
        }

        if ($this->app->payments->stripeAccount($providerId) === null) {
            $this->redirect(self::PATH);
            return;
        }

        $this->toStripe($providerId);
    }

    private function toStripe(int $providerId): void
    {
        $app = $this->app;
        if ((new RateLimiter($app->db))->hit('stripe-connect', (string) $app->auth->account()['id'], 10, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
            $this->redirect(self::PATH);
            return;
        }

        try {
            $url = $app->payments->stripeOnboardingUrl(
                $providerId,
                $app->url(self::PATH . '/stripe/refresh', null, true),
                $app->url(self::PATH . '/stripe/return', null, true)
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
            $this->redirect(self::PATH);
            return;
        }

        header('Location: ' . $url);
    }

    /** Back from Stripe, or "Update status": asks Stripe whether the account can take payments. */
    public function stripeStatus(array $params): void
    {
        $providerId = $this->providerId(Payments::STRIPE);
        if ($providerId === null) {
            return;
        }

        if ((new RateLimiter($this->app->db))->hit('stripe-status', (string) $this->app->auth->account()['id'], 30, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
            $this->redirect(self::PATH);
            return;
        }

        try {
            $status = $this->app->payments->refreshStripeStatus($providerId);
            if ($status !== null) {
                Session::flash($status === 'ready' ? 'success' : 'error', $this->trans('core.payment.stripe.status.' . $status));
            }
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        }

        $this->redirect(self::PATH);
    }

    public function delete(array $params): void
    {
        $providerId = $this->providerId();
        if ($providerId === null) {
            return;
        }

        $method = self::METHODS[$params['method']] ?? null;
        if ($method !== null) {
            $this->app->payments->deleteConfig($providerId, $method);
            Session::flash('success', $this->trans('core.payment.removed'));
        }

        $this->redirect(self::PATH);
    }

    /**
     * @param string|null $method a method that must be allowed by the operator for the action to go on
     * @return int|null the logged-in account's provider id; without one, or
     *         with the method switched off, the redirect has been sent
     */
    private function providerId(?string $method = null): ?int
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            $this->redirect('/account/provider');
            return null;
        }
        if ($method !== null && !$this->app->payments->isEnabled($method)) {
            $this->redirect(self::PATH);
            return null;
        }

        return (int) $provider['id'];
    }

    /** @param array<string, string> $typedTransfer bank details as typed, after a refused attempt */
    private function renderPage(int $providerId, array $errors, array $typedTransfer): void
    {
        $payments = $this->app->payments;
        $stored = $payments->transferDetails($providerId);
        $paypal = $payments->config($providerId, Payments::PAYPAL);
        $stripe = $payments->config($providerId, Payments::STRIPE);

        $this->render('account/payments.twig', [
            'errors' => $errors,
            'offline' => $payments->isEnabled(Payments::OFFLINE),
            'transfer' => $payments->isEnabled(Payments::TRANSFER) ? [
                'ready' => $stored !== null,
            ] + $typedTransfer + ($stored !== null ? ['iban' => BankAccount::formatIban($stored['iban'])] + $stored : ['holder' => '', 'iban' => '', 'bic' => '', 'bank' => '']) : null,
            // The secret itself never reaches a template.
            'paypal' => $payments->isEnabled(Payments::PAYPAL) ? [
                'status' => $paypal['status'] ?? null,
                'client_id' => (string) ($paypal['data']['client_id'] ?? ''),
                'sandbox' => ($paypal['data']['sandbox'] ?? false) === true,
                'has_secret' => ($paypal['data']['secret'] ?? '') !== '',
            ] : null,
            'stripe' => $payments->isEnabled(Payments::STRIPE) ? [
                'platform_ready' => $payments->stripeConfigured(),
                'status' => $stripe['status'] ?? null,
                'account' => $payments->stripeAccount($providerId),
            ] : null,
        ]);
    }
}
