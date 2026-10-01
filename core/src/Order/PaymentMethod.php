<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\App;

/**
 * A way to pay for an order, registered with Registrar::paymentMethod().
 * The core ships one, OfflinePayment; a payment service (Stripe, Mangopay)
 * is an extension implementing this.
 */
interface PaymentMethod
{
    /** Unique and stable, starting with the extension id ("core." for the built-in one). */
    public function id(): string;

    /** Language key of the name shown to the buyer. */
    public function labelKey(): string;

    /** Language key of the explanation shown in the order form and on the order page. */
    public function descriptionKey(): string;

    /**
     * Whether the provider confirms the payment by hand. False for a
     * payment service, which reports payments itself through
     * Orders::markPaid().
     */
    public function confirmedByProvider(): bool;

    /**
     * Called once the order exists.
     *
     * @return string|null an address to send the buyer to in order to pay,
     *         or null if there is nothing to do right now
     */
    public function begin(array $order, App $app): ?string;
}
