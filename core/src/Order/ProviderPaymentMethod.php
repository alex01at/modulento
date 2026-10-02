<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\App;

/**
 * A way to pay that each provider sets up for themselves (bank details, an
 * account at a payment service). Optional: a PaymentMethod without it is
 * offered for every provider.
 *
 * For such a method begin() may be called again while the order is unpaid -
 * the buyer came back to pay, after breaking off or after choosing another
 * way to pay - and may throw a Payment\PaymentException if the service
 * cannot start the payment.
 */
interface ProviderPaymentMethod extends PaymentMethod
{
    /** Whether this provider has set the method up, so that buyers can be offered it. */
    public function availableFor(int $providerId, App $app): bool;
}
