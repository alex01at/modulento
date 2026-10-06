<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

/**
 * What customers see of subscriptions: the plans on offer, and the account's own.
 * Routed only while the module "Subscriptions" is switched on (see Kernel).
 */
final class SubscriptionController extends Controller
{
    public function overview(array $params): void
    {
        $app = $this->app;
        $this->render('subscriptions/index.twig', [
            'plans' => array_map(fn (array $plan) => $plan + ['feature_labels' => $this->labels($plan['features'])], $app->subscriptions->activePlans()),
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

    /** The names of the features as the site shows them; a key nobody declared is shown as it is. */
    private function labels(array $features): array
    {
        $declared = $this->app->subscriptions->declaredFeatures();

        return array_map(fn (string $key) => isset($declared[$key]) ? $this->trans($declared[$key]) : $key, $features);
    }
}
