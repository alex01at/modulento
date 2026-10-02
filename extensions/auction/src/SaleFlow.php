<?php

declare(strict_types=1);

namespace Modulento\Auction;

use Modulento\Core\App;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\Orders;

/**
 * What follows the hammer:
 *
 *   sold -> handed_over -> completed
 *
 * The order is created by Auctions when a lot ends with a bid, never
 * through the order form. Buyer and provider settle the payment between
 * them; the provider marks the lot as handed over or sent, the buyer
 * confirms having received it. Either side can ask to cancel and the
 * other answers; an administrator can cancel at any time. A handover the
 * buyer does not answer counts as confirmed.
 */
final class SaleFlow implements OrderFlow
{
    public const ID = 'auction.sale';

    private const CONFIRM_WITHIN_SECONDS = 14 * 86400;

    public function id(): string
    {
        return self::ID;
    }

    public function offerType(): string
    {
        return LotType::ID;
    }

    public function checkout(): bool
    {
        return false;
    }

    public function initialState(): string
    {
        return 'sold';
    }

    public function states(): array
    {
        return [
            'sold' => ['label' => 'auction.state.sold', 'entered' => 'auction.done.sold'],
            'handed_over' => ['label' => 'auction.state.handed_over'],
            'cancel_requested' => ['label' => 'auction.state.cancel_requested'],
            'completed' => ['label' => 'auction.state.completed', 'final' => true, 'reviewable' => true],
            'cancelled' => ['label' => 'auction.state.cancelled', 'final' => true],
        ];
    }

    public function transitions(): array
    {
        return [
            'hand_over' => ['from' => ['sold'], 'to' => 'handed_over', 'actor' => ['provider'], 'label' => 'auction.action.hand_over', 'done' => 'auction.done.hand_over', 'note' => 'optional'],
            'confirm' => ['from' => ['handed_over'], 'to' => 'completed', 'actor' => ['buyer'], 'label' => 'auction.action.confirm', 'done' => 'auction.done.confirm'],
            'auto_complete' => ['from' => ['handed_over'], 'to' => 'completed', 'actor' => ['system'], 'label' => 'auction.action.auto_complete', 'done' => 'auction.done.auto_complete'],

            'request_cancel' => ['from' => ['sold', 'handed_over'], 'to' => 'cancel_requested', 'actor' => ['buyer', 'provider'], 'label' => 'auction.action.request_cancel', 'done' => 'auction.done.request_cancel', 'note' => 'required'],
            'agree_cancel' => ['from' => ['cancel_requested'], 'to' => 'cancelled', 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'auction.action.agree_cancel', 'done' => 'auction.done.agree_cancel'],
            'refuse_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'auction.action.refuse_cancel', 'done' => 'auction.done.refuse_cancel', 'note' => 'optional'],
            'withdraw_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'initiator', 'label' => 'auction.action.withdraw_cancel', 'done' => 'auction.done.withdraw_cancel'],

            'admin_cancel' => ['from' => ['sold', 'handed_over', 'cancel_requested'], 'to' => 'cancelled', 'actor' => ['admin'], 'label' => 'auction.action.admin_cancel', 'done' => 'auction.done.admin_cancel', 'note' => 'required'],
        ];
    }

    public function deadline(string $state, array $order): ?array
    {
        return $state === 'handed_over' ? ['seconds' => self::CONFIRM_WITHIN_SECONDS, 'transition' => 'auto_complete'] : null;
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
        return '@auction/order_detail.twig';
    }

    public function orderDetailData(array $order, string $locale, App $app): array
    {
        return [
            'bids' => (int) ($order['data']['bids'] ?? 0),
            'start_price' => (int) ($order['data']['start_price'] ?? 0),
            'ended_at' => $order['data']['ended_at'] ?? null,
            'currency' => $order['currency'],
        ];
    }
}
