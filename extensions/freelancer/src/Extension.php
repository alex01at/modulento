<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;

/**
 * Turns the catalogue into a marketplace for services: an offer of the
 * type "freelancer.service" has packages with a price, a delivery time and
 * a number of revisions, optional extras, and a note on what the provider
 * needs from the buyer.
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $registrar->offerType(new ServiceType());
    }
}
