<?php

declare(strict_types=1);

namespace Modulento\Core\Subscription;

/**
 * What a billing addon (modulento-ext-abos) implements and hands to the
 * core with Registrar::subscriptionProvider() - the other side of
 * Subscriptions, which the rest of the core and other extensions ask
 * through instead of this interface directly.
 */
interface SubscriptionProvider
{
    public function allows(int $accountId, string $feature): bool;

    public function grants(int $accountId, string $feature): bool;

    public function offerLimit(int $accountId, string $offerType): ?int;

    public function imageLimit(int $accountId): ?int;

    /** @return int[] */
    public function accountIdsWithFeature(string $feature): array;

    public function activePlans(): array;

    public function plans(): array;

    public function current(int $accountId): ?array;

    public function setQuantity(int $accountId, int $quantity): void;
}
