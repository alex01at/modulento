<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use Modulento\Core\App;
use Modulento\Core\Order\ProviderPaymentMethod;

/**
 * Bank transfer to the provider's own account. The buyer reads the bank
 * details on the order page; the provider confirms the receipt, since the
 * platform cannot see into a bank account.
 */
final class TransferPayment implements ProviderPaymentMethod
{
    public function id(): string
    {
        return Payments::TRANSFER;
    }

    public function labelKey(): string
    {
        return 'core.payment.transfer.label';
    }

    public function descriptionKey(): string
    {
        return 'core.payment.transfer.description';
    }

    public function confirmedByProvider(): bool
    {
        return true;
    }

    public function begin(array $order, App $app): ?string
    {
        return null;
    }

    public function availableFor(int $providerId, App $app): bool
    {
        return $app->payments->isReady($providerId, Payments::TRANSFER);
    }
}
