<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Modulento\Core\App;
use Modulento\Core\Order\ProviderPaymentMethod;

/** Card and the other ways Stripe Checkout offers, paid straight into the provider's Stripe account. */
final class StripePayment implements ProviderPaymentMethod
{
    public function id(): string
    {
        return Payments::STRIPE;
    }

    public function labelKey(): string
    {
        return 'core.payment.stripe.label';
    }

    public function descriptionKey(): string
    {
        return 'core.payment.stripe.description';
    }

    public function confirmedByProvider(): bool
    {
        return false;
    }

    public function begin(array $order, App $app): ?string
    {
        return $app->payments->startStripe($order, $app);
    }

    public function availableFor(int $providerId, App $app): bool
    {
        // Without the platform's own keys no connected account can be charged.
        return $app->payments->stripeConfigured() && $app->payments->isReady($providerId, Payments::STRIPE);
    }
}
