<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\Session;
use RuntimeException;

/**
 * The operator decides which ways to pay the site allows, and gives the
 * platform's own Stripe account its keys. Money never passes through that
 * account: it only creates and charges the providers' connected accounts.
 */
final class AdminPaymentController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $payments = $app->payments;
        $hints = $payments->stripeHints();

        $this->render('@admin/payments.twig', [
            'encryption' => $payments->encryptionAvailable(),
            'methods' => array_map(fn ($method) => [
                'id' => $method->id(),
                'label_key' => $method->labelKey(),
                'enabled' => $payments->isEnabled($method->id()),
                'needs_encryption' => in_array($method->id(), Payments::NEEDS_ENCRYPTION, true),
            ], array_values($app->orders->paymentMethods())),
            'stripe' => [
                'enabled' => $payments->isEnabled(Payments::STRIPE),
                // The last characters only: enough to tell two keys apart.
                'secret_key_hint' => $hints['secret_key'],
                'webhook_secret_hint' => $hints['webhook_secret'],
                // Without a language prefix: Stripe does not read a language.
                'webhook_url' => $app->config['app']['url'] . '/webhooks/stripe',
            ],
        ]);
    }

    public function save(array $params): void
    {
        $app = $this->app;
        $payments = $app->payments;
        $wanted = array_map('strval', is_array($_POST['methods'] ?? null) ? array_filter($_POST['methods'], 'is_string') : []);

        $enabled = [];
        foreach (array_keys($app->orders->paymentMethods()) as $id) {
            $enabled[$id] = in_array($id, $wanted, true)
                && ($payments->encryptionAvailable() || !in_array($id, Payments::NEEDS_ENCRYPTION, true));
        }

        $secretKey = trim(is_string($_POST['stripe_secret_key'] ?? null) ? $_POST['stripe_secret_key'] : '');
        $webhookSecret = trim(is_string($_POST['stripe_webhook_secret'] ?? null) ? $_POST['stripe_webhook_secret'] : '');

        $error = match (true) {
            // Orders could not be placed at all any more.
            !in_array(true, $enabled, true) => 'core.admin.payments.error.none',
            // A publishable key ("pk_...") pasted by mistake cannot create anything.
            $secretKey !== '' && preg_match('/^(sk|rk)_[A-Za-z0-9_]{8,250}$/', $secretKey) !== 1 => 'core.admin.payments.error.secret_key',
            $webhookSecret !== '' && preg_match('/^whsec_[\x21-\x7E]{8,250}$/', $webhookSecret) !== 1 => 'core.admin.payments.error.webhook_secret',
            default => null,
        };
        if ($error !== null) {
            Session::flash('error', $this->trans($error));
            $this->redirect('/admin/payments');
            return;
        }

        try {
            if (isset($_POST['stripe_remove'])) {
                $payments->saveStripeKeys('', '');
            } elseif ($payments->encryptionAvailable()) {
                // An empty field leaves the stored key as it is.
                $payments->saveStripeKeys($secretKey !== '' ? $secretKey : null, $webhookSecret !== '' ? $webhookSecret : null);
            }
        } catch (RuntimeException $e) {
            error_log('Payment settings: ' . $e->getMessage());
            Session::flash('error', $this->trans('core.admin.payments.error.key_file'));
            $this->redirect('/admin/payments');
            return;
        }

        foreach ($enabled as $id => $on) {
            $payments->setEnabled($id, $on);
        }

        Session::flash('success', $this->trans('core.admin.payments.saved'));
        $this->redirect('/admin/payments');
    }
}
