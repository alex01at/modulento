<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Modulento\Core\App;
use Modulento\Core\Order\ProviderPaymentMethod;

/** PayPal, paid straight into the provider's PayPal account. */
final class PaypalPayment implements ProviderPaymentMethod
{
    public function id(): string
    {
        return Payments::PAYPAL;
    }

    public function labelKey(): string
    {
        return 'core.payment.paypal.label';
    }

    public function descriptionKey(): string
    {
        return 'core.payment.paypal.description';
    }

    public function confirmedByProvider(): bool
    {
        return false;
    }

    public function begin(array $order, App $app): ?string
    {
        return $app->payments->startPaypal($order, $app);
    }

    public function availableFor(int $providerId, App $app): bool
    {
        return $app->payments->isReady($providerId, Payments::PAYPAL);
    }
}
