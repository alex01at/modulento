<?php

declare(strict_types=1);

namespace Modulento\Core\Catalogue;

use Modulento\Core\App;

/**
 * An offer type that can be filtered and sorted by its own fields in the
 * public list. The visitor picks the type on /offers (?type=<id>); the list
 * then shows the type's filter inputs, named f[<key>], and hands what was
 * sent to applyFilter().
 *
 * The SQL a type returns runs inside the list's query, where the offer is
 * the table "o" (offer.*). Parameters must be named ":f_<name>", so they can
 * never clash with the core's own.
 */
interface FilterableOfferType extends OfferType
{
    /** Template with the filter inputs (names f[<key>]), included in the list's search form. It receives "filter". */
    public function filterTemplate(): string;

    /**
     * The data for filterTemplate(): the choices to offer and what was chosen.
     *
     * @param array<string, mixed> $input the visitor's f[...] values, as sent
     * @return array<string, mixed>
     */
    public function filterData(array $input, string $locale, App $app): array;

    /**
     * A condition on the offers, from the visitor's f[...] values. Values
     * that make no sense (a text where a number is expected) are to be
     * ignored, not trusted.
     *
     * @param array<string, mixed> $input
     * @return array{where: string, params: array<string, mixed>}|null null: no condition
     */
    public function applyFilter(array $input, string $locale): ?array;

    /**
     * The sorts this type adds to the core's, by name, each with the
     * language key of its label. Names must not clash with the core's.
     *
     * @return array<string, string>
     */
    public function sorts(): array;

    /** The ORDER BY terms (without "ORDER BY") of one of sorts(), or null for an unknown name. */
    public function orderBy(string $sort): ?string;
}
