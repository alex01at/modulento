<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use InvalidArgumentException;
use Modulento\Core\Payment\Payments;
use Modulento\Core\Payment\PaymentException;
use Modulento\Core\Support\Session;

/**
 * What customers see of subscriptions and do with them: the plans on offer, ordering
 * one by transfer or Stripe, the order's page, and ending a Stripe subscription.
 * Routed only while the module "Subscriptions" is switched on (see Kernel).
 */
final class SubscriptionController extends Controller
{
    public function overview(array $params): void
    {
        $app = $this->app;
        $this->render('subscriptions/index.twig', [
            'plans' => array_map(fn (array $plan) => $plan + ['feature_labels' => $this->labels($plan['features'])], $app->subscriptions->activePlans()),
            'methods' => $app->subscriptionBilling->methods(),
            'signed_in' => $app->auth->account() !== null,
        ]);
    }

    public function account(array $params): void
    {
        $app = $this->app;
        $current = $app->subscriptions->current((int) $app->auth->account()['id']);
        $this->render('account/subscription.twig', [
            'current' => $current === null ? null : $current + ['feature_labels' => $this->labels($current['features'])],
        ]);
    }

    /** The buyer chooses a way to pay: a transfer shows the bank details, Stripe leads to its checkout. */
    public function order(array $params): void
    {
        $app = $this->app;
        $accountId = (int) $app->auth->account()['id'];
        $planId = (int) $params['id'];
        $method = (string) ($_POST['method'] ?? '');
        $offered = $app->subscriptionBilling->methods();

        try {
            if ($method === 'transfer' && $offered['transfer']) {
                $order = $app->subscriptionBilling->startTransfer($accountId, $planId);
                $this->redirect('/subscriptions/orders/' . $order['id']);
                return;
            }
            if ($method === 'stripe' && $offered['stripe']) {
                $url = $app->subscriptionBilling->startStripe(
                    $accountId,
                    $planId,
                    $app->url('/account/subscription', null, true),
                    $app->url('/subscriptions', null, true)
                );
                header('Location: ' . $url);
                return;
            }
            Session::flash('error', $this->trans('core.subscriptions.error.method'));
        } catch (PaymentException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        } catch (InvalidArgumentException) {
            Session::flash('error', $this->trans('core.subscriptions.error.unavailable'));
        }

        $this->redirect('/subscriptions');
    }

    /** One of the account's own orders: the bank details while the transfer is open, the state afterwards. */
    public function orderPage(array $params): void
    {
        $app = $this->app;
        $order = $app->subscriptionBilling->orderOf((int) $app->auth->account()['id'], (int) $params['id']);
        if ($order === null) {
            http_response_code(404);
            $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
            return;
        }

        $this->render('subscriptions/order.twig', [
            'order' => $order,
            'bank' => $order['method'] === 'transfer' && $order['status'] === 'pending' ? $app->subscriptionBilling->bank() : null,
        ]);
    }

    public function cancel(array $params): void
    {
        $app = $this->app;
        try {
            if ($app->subscriptionBilling->cancelOwn((int) $app->auth->account()['id'])) {
                Session::flash('success', $this->trans('core.account.subscription.cancelled'));
            } else {
                Session::flash('error', $this->trans('core.account.subscription.cancel_none'));
            }
        } catch (PaymentException $e) {
            Session::flash('error', $this->trans(Payments::report($e)));
        }

        $this->redirect('/account/subscription');
    }

    /** The names of the features as the site shows them; a key nobody declared is shown as it is. */
    private function labels(array $features): array
    {
        $declared = $this->app->subscriptions->declaredFeatures();

        return array_map(fn (string $key) => isset($declared[$key]) ? $this->trans($declared[$key]) : $key, $features);
    }
}
