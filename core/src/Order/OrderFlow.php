<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\App;

/**
 * How offers of one type are ordered and carried out, supplied by an
 * extension and registered with Registrar::orderFlow().
 *
 * The flow describes; the core executes. A transition is applied by
 * Orders::apply(), which checks state, actor and guard, changes the state
 * atomically, writes the history and notifies the other party - a flow
 * never updates an order itself.
 */
interface OrderFlow
{
    /** Unique and stable, starting with the extension id. */
    public function id(): string;

    /** The offer type (OfferType::id()) whose offers are ordered through this flow. */
    public function offerType(): string;

    /** The state a new order starts in. */
    public function initialState(): string;

    /**
     * @return array<string, array{label: string, final?: bool}> state =>
     *         language key of its name, and whether the order ends there
     */
    public function states(): array;

    /**
     * @return array<string, array{from: string[], to: string, actor: string[], label: string, done?: string, note?: string, files?: bool, by?: string}>
     *         transition name =>
     *         - from: states it can start in
     *         - to: the resulting state, or Orders::PREVIOUS for "back to
     *           where the order was before the current state"
     *         - actor: who may apply it - "buyer", "provider", "admin",
     *           "system" (deadlines only)
     *         - label: language key of the action, shown on its button
     *         - done: language key for the history and for e-mails, where
     *           the action has happened ("Order accepted"); the label is
     *           used if it is missing
     *         - note: "required" or "optional"; without it no text is asked
     *         - files: true lets files be attached to the step (a delivery)
     *         - by: "counterparty" allows only the other side than the one
     *           that caused the current state, "initiator" only that side
     */
    public function transitions(): array;

    /**
     * What happens by itself if nobody acts while the order is in a state.
     *
     * @return array{seconds: int, transition: string}|null
     */
    public function deadline(string $state, array $order): ?array;

    /** A further condition for a transition, beyond state and actor (e.g. "revisions left"). */
    public function allows(string $transition, array $order, App $app): bool;

    /** Template included in the order form for the buyer's choices. It receives "flow_data". */
    public function orderFormTemplate(): string;

    /**
     * The data for orderFormTemplate().
     *
     * @param array<string, mixed> $input the request (GET for the first
     *        view, POST when the form is shown again)
     * @return array<string, mixed>
     */
    public function orderFormData(array $offer, array $input, string $locale, App $app): array;

    /**
     * Turns the buyer's choices into an order. Prices are read from the
     * offer here, on the server, never taken from the request.
     *
     * @param array<string, mixed> $input the request's POST data
     * @return array{items: array<int, array{label: string, quantity: int, unit_price: int}>, data: array<string, mixed>, errors: string[]}
     *         items with amounts in minor units, data for the order's
     *         "data" column, errors as language keys
     */
    public function build(array $offer, array $input, string $locale, App $app): array;

    /** Template included on the order page for what the flow knows about the order. It receives "flow_data". */
    public function orderDetailTemplate(): string;

    /** @return array<string, mixed> the data for orderDetailTemplate() */
    public function orderDetailData(array $order, string $locale, App $app): array;
}
