<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/**
 * A kind of offer, supplied by an extension and registered with
 * Registrar::offerType(): a freelancer service, a dish, an auction lot.
 *
 * The core owns what every offer has - provider, category, status, title
 * and text per language, pictures - and the pages around it. The type owns
 * everything else: its fields in the form, its own tables (which reference
 * offer (id) with ON DELETE CASCADE), and how the offer is presented.
 */
interface OfferType
{
    /** Unique and stable, starting with the extension id: "freelancer.service". */
    public function id(): string;

    /** Language key of the name shown to providers and administrators. */
    public function labelKey(): string;

    /** Template included inside the offer form for this type's fields. It receives "type_data". */
    public function formTemplate(): string;

    /** Template included on the public offer page. It receives "type_data". */
    public function detailTemplate(): string;

    /**
     * The data for formTemplate().
     *
     * @param int|null $offerId null for a new offer
     * @param array<string, mixed>|null $typed the form as it was sent, when
     *        it is shown again after a failed validation
     * @param string[]|null $locales restricts per-language fields to these
     *        (the wizard's own step 1 choice); null means every site locale,
     *        as when editing an offer that already exists
     * @return array<string, mixed>
     */
    public function formData(?int $offerId, ?array $typed, App $app, ?array $locales = null): array;

    /**
     * Checks this type's part of the submitted form.
     *
     * @param array<string, mixed> $input the request's POST data
     * @param int|null $offerId null for a new offer; a type whose offers
     *        may not change any more once something depends on them (bids
     *        on a lot) decides that here
     * @return array{values: array<string, mixed>, errors: string[]} errors
     *         as language keys; values are handed to save() unchanged
     */
    public function validate(array $input, ?int $offerId, App $app): array;

    /**
     * Stores this type's data for an offer the core has just saved.
     *
     * @param array<string, mixed> $values from validate()
     * @return int|null the offer's lowest price in minor units, for
     *         listings and sorting, or null if it has no fixed price
     */
    public function save(int $offerId, array $values, App $app): ?int;

    /**
     * The data for detailTemplate(), in the given language.
     *
     * @return array<string, mixed>
     */
    public function detailData(int $offerId, string $locale, App $app): array;
}
