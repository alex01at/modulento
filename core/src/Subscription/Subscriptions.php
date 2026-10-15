<?php

declare(strict_types=1);

namespace Modulento\Core\Subscription;

use InvalidArgumentException;

/**
 * What the rest of the core - and an extension that sells a feature, like
 * the card extension's per-seat team billing - asks about an account's
 * plan. The billing itself is never implemented here: an addon
 * (modulento-ext-abos) registers a SubscriptionProvider through
 * Registrar::subscriptionProvider(). Without one installed, every feature
 * is open (allows()) and no bonus is granted (grants()) - there is nothing
 * to have paid for, so nothing is held back for payment. An extension
 * still declares what it sells with declareFeature() whether or not a
 * provider is installed yet, so a provider added later already has every
 * name and label an operator's plan editor needs.
 */
final class Subscriptions
{
    /** @var array<string, string> feature key => language key of its name, declared by extensions */
    private array $declared = [];
    private ?SubscriptionProvider $provider = null;

    public function registerProvider(SubscriptionProvider $provider): void
    {
        $this->provider = $provider;
    }

    public function hasProvider(): bool
    {
        return $this->provider !== null;
    }

    /** An extension declares a feature it sells, so that a plan can include it by choice. */
    public function declareFeature(string $key, string $labelKey): void
    {
        if (preg_match('/^[a-z0-9._-]{1,60}$/', $key) !== 1) {
            throw new InvalidArgumentException('feature: key');
        }
        $this->declared[$key] = $labelKey;
    }

    /** @return array<string, string> */
    public function declaredFeatures(): array
    {
        return $this->declared;
    }

    /** Whether the account may use the feature. Without a billing addon installed, every feature is open. */
    public function allows(int $accountId, string $feature): bool
    {
        return $this->provider === null || $this->provider->allows($accountId, $feature);
    }

    /**
     * Whether the account's plan grants it a bonus on top of what everyone
     * already has - the opposite default of allows(): without a billing
     * addon installed, nothing is granted, since there is no plan to have
     * granted it. Use this for a privilege (skip review, a badge, better
     * placement); use allows() for a feature that is closed without a plan.
     */
    public function grants(int $accountId, string $feature): bool
    {
        return $this->provider !== null && $this->provider->grants($accountId, $feature);
    }

    /** How many offers of this type the account's plan allows; null for no limit (also the answer without a billing addon installed). */
    public function offerLimit(int $accountId, string $offerType): ?int
    {
        return $this->provider?->offerLimit($accountId, $offerType);
    }

    /** Same as offerLimit(), for pictures per offer - not tied to a type. */
    public function imageLimit(int $accountId): ?int
    {
        return $this->provider?->imageLimit($accountId);
    }

    /**
     * Every account whose plan currently grants a feature - for a listing
     * that wants to place them first, not just answer "may this one account".
     * Empty without a billing addon installed, same as allows() has nothing to check.
     *
     * @return int[]
     */
    public function accountIdsWithFeature(string $feature): array
    {
        return $this->provider?->accountIdsWithFeature($feature) ?? [];
    }

    /** The plans that can be booked now, for a public overview - empty without a billing addon installed. */
    public function activePlans(): array
    {
        return $this->provider?->activePlans() ?? [];
    }

    /** Every plan, active or not - for the administration. Empty without a billing addon installed. */
    public function plans(): array
    {
        return $this->provider?->plans() ?? [];
    }

    /** The subscription that counts for the account now, or null. */
    public function current(int $accountId): ?array
    {
        return $this->provider?->current($accountId);
    }

    /**
     * Changes the seat count of an account's running per-seat subscription -
     * the entry point an extension calls when a member joins or leaves a
     * team it bills for (see the card extension's Organizations class).
     * Does nothing without a billing addon installed.
     */
    public function setQuantity(int $accountId, int $quantity): void
    {
        $this->provider?->setQuantity($accountId, $quantity);
    }
}
