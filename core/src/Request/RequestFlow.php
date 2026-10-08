<?php

declare(strict_types=1);

namespace Modulento\Core\Request;

use Modulento\Core\App;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\Orders;

/**
 * What follows accepting an application:
 *
 *   in_progress -> delivered -> completed
 *
 * The order is created by RequestApplications::accept() - the buyer's own
 * choice of payment method and accepted terms, never through the regular
 * order form (there is nothing left to configure: price and provider are
 * already fixed by the application). Core's own flow, not borrowed from
 * any extension's - a request is not tied to freelancer or auction being
 * installed at all.
 */
final class RequestFlow implements OrderFlow
{
    public const ID = 'core.request';

    private const CONFIRM_WITHIN_SECONDS = 14 * 86400;

    public function id(): string
    {
        return self::ID;
    }

    public function offerType(): string
    {
        return self::ID;
    }

    public function checkout(): bool
    {
        return false;
    }

    public function initialState(): string
    {
        return 'in_progress';
    }

    public function states(): array
    {
        return [
            'in_progress' => ['label' => 'core.request.state.in_progress', 'entered' => 'core.request.done.in_progress'],
            'delivered' => ['label' => 'core.request.state.delivered'],
            'cancel_requested' => ['label' => 'core.request.state.cancel_requested'],
            'completed' => ['label' => 'core.request.state.completed', 'final' => true, 'reviewable' => true],
            'cancelled' => ['label' => 'core.request.state.cancelled', 'final' => true],
        ];
    }

    public function transitions(): array
    {
        return [
            'deliver' => ['from' => ['in_progress'], 'to' => 'delivered', 'actor' => ['provider'], 'label' => 'core.request.action.deliver', 'done' => 'core.request.done.deliver', 'note' => 'required', 'files' => true],
            'accept_delivery' => ['from' => ['delivered'], 'to' => 'completed', 'actor' => ['buyer'], 'label' => 'core.request.action.accept_delivery', 'done' => 'core.request.done.accept_delivery'],
            'request_revision' => ['from' => ['delivered'], 'to' => 'in_progress', 'actor' => ['buyer'], 'label' => 'core.request.action.request_revision', 'done' => 'core.request.done.request_revision', 'note' => 'required'],
            'auto_complete' => ['from' => ['delivered'], 'to' => 'completed', 'actor' => ['system'], 'label' => 'core.request.action.auto_complete', 'done' => 'core.request.done.auto_complete'],

            'request_cancel' => ['from' => ['in_progress', 'delivered'], 'to' => 'cancel_requested', 'actor' => ['buyer', 'provider'], 'label' => 'core.request.action.request_cancel', 'done' => 'core.request.done.request_cancel', 'note' => 'required'],
            'agree_cancel' => ['from' => ['cancel_requested'], 'to' => 'cancelled', 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'core.request.action.agree_cancel', 'done' => 'core.request.done.agree_cancel'],
            'refuse_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'core.request.action.refuse_cancel', 'done' => 'core.request.done.refuse_cancel', 'note' => 'optional'],
            'withdraw_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'initiator', 'label' => 'core.request.action.withdraw_cancel', 'done' => 'core.request.done.withdraw_cancel'],

            'admin_cancel' => ['from' => ['in_progress', 'delivered', 'cancel_requested'], 'to' => 'cancelled', 'actor' => ['admin'], 'label' => 'core.request.action.admin_cancel', 'done' => 'core.request.done.admin_cancel', 'note' => 'required'],
        ];
    }

    public function deadline(string $state, array $order): ?array
    {
        return $state === 'delivered' ? ['seconds' => self::CONFIRM_WITHIN_SECONDS, 'transition' => 'auto_complete'] : null;
    }

    public function allows(string $transition, array $order, App $app): bool
    {
        return true;
    }

    // --- Not used: checkout() is false ------------------------------------------

    public function orderFormTemplate(): string
    {
        return '';
    }

    public function orderFormData(array $offer, array $input, string $locale, App $app): array
    {
        return [];
    }

    public function build(array $offer, array $input, string $locale, App $app): array
    {
        return ['items' => [], 'data' => [], 'errors' => ['core.order.error.not_possible']];
    }

    // -----------------------------------------------------------------------------

    public function orderDetailTemplate(): string
    {
        return 'order/_request_detail.twig';
    }

    /** @return array{request_id: ?int, request_title: ?string, request_path: ?string} */
    public function orderDetailData(array $order, string $locale, App $app): array
    {
        $requestId = isset($order['data']['request_id']) ? (int) $order['data']['request_id'] : null;
        $request = $requestId !== null ? $app->requests->find($requestId) : null;

        return [
            'request_id' => $requestId,
            'request_title' => $request['title'] ?? null,
            'request_path' => $request !== null ? '/requests/' . $request['slug'] : null,
        ];
    }
}
