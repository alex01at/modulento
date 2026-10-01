<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

/**
 * Dispatched when a provider profile is created or its status changes.
 * Extensions hide or show what the provider offers accordingly: only
 * "approved" is public.
 */
final class ProviderStatusChanged
{
    public function __construct(
        public readonly int $providerId,
        public readonly int $accountId,
        public readonly ?string $oldStatus,
        public readonly string $newStatus,
    ) {
    }
}
