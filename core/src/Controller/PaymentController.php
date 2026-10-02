<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Order\ProviderPaymentMethod;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;
use RuntimeException;

/**
 * Paying an order after it was placed: starting the payment at the service,
 * coming back from there, choosing another way to pay - and the address
 * Stripe reports payments to.
 */
final class PaymentController extends Controller
{
    /** "Pay now": starts the payment of an unpaid order (again) and sends the buyer to the service. */
    public function pay(array $params): void
    {
        $order = $this->payableOrder($params);
        if ($order === null) {
            return;
        }

        $app = $this->app;
        $back = '/orders/' . $order['id'];
        $method = $app->payments->availableFor($order['provider_id'], $app)[$order['payment_method']] ?? null;

        if (!$method instanceof ProviderPaymentMethod) {
            Session::flash('error', $this->trans('core.payment.error.choose'));
            $this->redirect($back);
            return;
        }
        if ((new RateLimiter($app->db))->hit('payment-start', (string) $app->auth->account()['id'], 20, 3600)) {
            Session::flash('error', $this->trans('core.error.too_many_requests'));
            $this->redirect($back);
            return;
        }

        try {
            // A payment that went through although the buyer never came
            // back here must not be paid a second time.
            $pending = $app->payments->newestPending($order['id']);
            if ($pending !== null && $app->payments->confirm($app, $pending)) {
                Session::flash('success', $this->trans('core.payment.received'));
                $this->redirect($back);
                return;
            }

            $payUrl = $method->begin($order, $app);
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
            $this->redirect($back);
            return;
        }

        if ($payUrl !== null) {
            header('Location: ' . $payUrl);
            return;
        }

        $this->redirect($back);
    }

    /** The buyer chooses another way to pay, while nothing has been paid. */
    public function choose(array $params): void
    {
        $order = $this->payableOrder($params);
        if ($order === null) {
            return;
        }

        $app = $this->app;
        $methodId = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';

        // Only what this order's provider offers - never what the form claims.
        if (isset($app->payments->availableFor($order['provider_id'], $app)[$methodId])) {
            $app->orders->setPaymentMethod($order['id'], $methodId);
            Session::flash('success', $this->trans('core.payment.method_changed'));
        } else {
            Session::flash('error', $this->trans('core.order.error.payment_method'));
        }

        $this->redirect('/orders/' . $order['id']);
    }

    /**
     * Where the service sends the buyer after paying. Nothing in the
     * address is believed: the payment is the one stored for this order of
     * this buyer, and the service is asked what became of it.
     */
    public function back(array $params): void
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);
        $payment = $app->payments->find((int) $params['payment']);

        if ($order === null || $order['buyer_id'] !== $app->auth->account()['id'] || $payment === null || (int) $payment['order_id'] !== $order['id']) {
            $this->notFound();
            return;
        }

        try {
            if ($app->payments->confirm($app, $payment)) {
                Session::flash('success', $this->trans('core.payment.received'));
            } elseif ($app->orders->find($order['id'])['payment_state'] === 'unpaid') {
                Session::flash('error', $this->trans('core.payment.not_received'));
            }
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        }

        $this->redirect('/orders/' . $order['id']);
    }

    public function stripeWebhook(array $params): void
    {
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $status = $this->app->payments->handleStripeWebhook(
            $this->app,
            (string) file_get_contents('php://input'),
            is_string($signature) ? $signature : ''
        );

        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $status === 200 ? 'ok' : 'invalid signature';
    }

    /** @return array|null the logged-in buyer's own order, if it can still be paid; anything else is answered here */
    private function payableOrder(array $params): ?array
    {
        $app = $this->app;
        $order = $app->orders->find((int) $params['id']);

        if ($order === null || $order['buyer_id'] !== $app->auth->account()['id']) {
            $this->notFound();
            return null;
        }
        if (!$app->orders->isPayable($order)) {
            Session::flash('error', $this->trans('core.order.error.not_possible'));
            $this->redirect('/orders/' . $order['id']);
            return null;
        }

        return $order;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
