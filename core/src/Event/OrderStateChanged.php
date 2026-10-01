<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

/** Dispatched after an order was placed (old state null) or changed its state. */
final class OrderStateChanged
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?string $oldState,
        public readonly string $newState,
        public readonly string $transition,
        public readonly string $actorRole,
    ) {
    }
}
