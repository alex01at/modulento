<?php

declare(strict_types=1);

namespace Modulento\Core\Cookie;

use InvalidArgumentException;

/**
 * What an extension asks before it loads something that sets a cookie it
 * does not strictly need (an external CAPTCHA widget, an embed, a tracking
 * pixel) - never implemented here. A cookie-consent addon
 * (modulento-ext-cookie) registers a ConsentProvider through
 * Registrar::consentProvider() to own the banner and the visitor's choice.
 * Without one installed, granted() is false for every category: nothing
 * that needs consent may load, since there is no mechanism yet to have
 * asked for it. An extension still declares its category with
 * declareCategory() whether or not a consent addon is installed, so one
 * added later already has every name and label an operator's banner needs.
 */
final class Consent
{
    /** @var array<string, array{label_key: string, description_key: string}> category id => its labels, declared by extensions */
    private array $declared = [];
    private ?ConsentProvider $provider = null;

    public function registerProvider(ConsentProvider $provider): void
    {
        $this->provider = $provider;
    }

    public function hasProvider(): bool
    {
        return $this->provider !== null;
    }

    /** An extension declares a category of cookie it may set, so a consent addon's banner can list and ask about it. */
    public function declareCategory(string $id, string $labelKey, string $descriptionKey): void
    {
        if (preg_match('/^[a-z0-9._-]{1,60}$/', $id) !== 1) {
            throw new InvalidArgumentException('cookie category: id');
        }
        $this->declared[$id] = ['label_key' => $labelKey, 'description_key' => $descriptionKey];
    }

    /** @return array<string, array{label_key: string, description_key: string}> */
    public function declaredCategories(): array
    {
        return $this->declared;
    }

    /** Whether the visitor has agreed to this category. Without a consent addon installed, nothing is ever agreed to. */
    public function granted(string $category): bool
    {
        return $this->provider !== null && $this->provider->granted($category);
    }
}
