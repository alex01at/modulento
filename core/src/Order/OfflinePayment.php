<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\App;

/**
 * Buyer and provider settle the payment between themselves (bank transfer,
 * invoice). The platform never touches the money; it only records that the
 * provider confirmed having received it.
 */
final class OfflinePayment implements PaymentMethod
{
    public function id(): string
    {
        return 'core.offline';
    }

    public function labelKey(): string
    {
        return 'core.payment.offline.label';
    }

    public function descriptionKey(): string
    {
        return 'core.payment.offline.description';
    }

    public function confirmedByProvider(): bool
    {
        return true;
    }

    public function begin(array $order, App $app): ?string
    {
        return null;
    }
}
