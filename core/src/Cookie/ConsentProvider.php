<?php

declare(strict_types=1);

namespace Modulento\Core\Cookie;

/**
 * The other half of Consent: a cookie-consent addon (modulento-ext-cookie)
 * registers one through Registrar::consentProvider(). It owns the banner,
 * the visitor's choice, and the answer to whether a category was agreed to.
 */
interface ConsentProvider
{
    /** Whether the visitor has agreed to this category of cookie right now. */
    public function granted(string $category): bool;
}
