<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

/**
 * Dispatched when an offer's status changes, and with a null new status
 * when it is deleted. Only "published" is public.
 */
final class OfferStatusChanged
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $providerId,
        public readonly string $oldStatus,
        public readonly ?string $newStatus,
    ) {
    }
}
