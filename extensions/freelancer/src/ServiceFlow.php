<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\App;
use Modulento\Core\Order\OrderFlow;
use Modulento\Core\Order\Orders;

/**
 * How a service is ordered and carried out:
 *
 *   placed -> in_progress -> delivered -> completed
 *
 * The provider accepts or declines a new order; delivers; the buyer
 * accepts the delivery or asks for a revision as long as the package
 * includes one. While work is under way or delivered, either side can ask
 * to cancel and the other answers. An administrator can cancel at any
 * time. Unanswered orders expire, unanswered deliveries count as accepted.
 */
final class ServiceFlow implements OrderFlow
{
    private const ACCEPT_WITHIN_SECONDS = 3 * 86400;
    private const REVIEW_WITHIN_SECONDS = 7 * 86400;

    public function __construct(private ServiceType $type)
    {
    }

    public function id(): string
    {
        return 'freelancer.service';
    }

    public function offerType(): string
    {
        return $this->type->id();
    }

    public function checkout(): bool
    {
        return true;
    }

    public function initialState(): string
    {
        return 'placed';
    }

    public function states(): array
    {
        return [
            'placed' => ['label' => 'freelancer.state.placed'],
            'in_progress' => ['label' => 'freelancer.state.in_progress'],
            'delivered' => ['label' => 'freelancer.state.delivered'],
            'cancel_requested' => ['label' => 'freelancer.state.cancel_requested'],
            'completed' => ['label' => 'freelancer.state.completed', 'final' => true, 'reviewable' => true],
            'declined' => ['label' => 'freelancer.state.declined', 'final' => true],
            'cancelled' => ['label' => 'freelancer.state.cancelled', 'final' => true],
        ];
    }

    public function transitions(): array
    {
        return [
            'accept' => ['from' => ['placed'], 'to' => 'in_progress', 'actor' => ['provider'], 'label' => 'freelancer.action.accept', 'done' => 'freelancer.done.accept'],
            'decline' => ['from' => ['placed'], 'to' => 'declined', 'actor' => ['provider'], 'label' => 'freelancer.action.decline', 'done' => 'freelancer.done.decline', 'note' => 'required'],
            'withdraw' => ['from' => ['placed'], 'to' => 'cancelled', 'actor' => ['buyer'], 'label' => 'freelancer.action.withdraw', 'done' => 'freelancer.done.withdraw'],
            'expire' => ['from' => ['placed'], 'to' => 'declined', 'actor' => ['system'], 'label' => 'freelancer.action.expire', 'done' => 'freelancer.done.expire'],

            'deliver' => ['from' => ['in_progress'], 'to' => 'delivered', 'actor' => ['provider'], 'label' => 'freelancer.action.deliver', 'done' => 'freelancer.done.deliver', 'note' => 'required', 'files' => true],
            'accept_delivery' => ['from' => ['delivered'], 'to' => 'completed', 'actor' => ['buyer'], 'label' => 'freelancer.action.accept_delivery', 'done' => 'freelancer.done.accept_delivery'],
            'request_revision' => ['from' => ['delivered'], 'to' => 'in_progress', 'actor' => ['buyer'], 'label' => 'freelancer.action.request_revision', 'done' => 'freelancer.done.request_revision', 'note' => 'required', 'files' => true],
            'auto_complete' => ['from' => ['delivered'], 'to' => 'completed', 'actor' => ['system'], 'label' => 'freelancer.action.auto_complete', 'done' => 'freelancer.done.auto_complete'],

            'request_cancel' => ['from' => ['in_progress', 'delivered'], 'to' => 'cancel_requested', 'actor' => ['buyer', 'provider'], 'label' => 'freelancer.action.request_cancel', 'done' => 'freelancer.done.request_cancel', 'note' => 'required'],
            'agree_cancel' => ['from' => ['cancel_requested'], 'to' => 'cancelled', 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'freelancer.action.agree_cancel', 'done' => 'freelancer.done.agree_cancel'],
            'refuse_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'counterparty', 'label' => 'freelancer.action.refuse_cancel', 'done' => 'freelancer.done.refuse_cancel', 'note' => 'optional'],
            'withdraw_cancel' => ['from' => ['cancel_requested'], 'to' => Orders::PREVIOUS, 'actor' => ['buyer', 'provider'], 'by' => 'initiator', 'label' => 'freelancer.action.withdraw_cancel', 'done' => 'freelancer.done.withdraw_cancel'],

            'admin_cancel' => ['from' => ['placed', 'in_progress', 'delivered', 'cancel_requested'], 'to' => 'cancelled', 'actor' => ['admin'], 'label' => 'freelancer.action.admin_cancel', 'done' => 'freelancer.done.admin_cancel', 'note' => 'required'],
        ];
    }

    public function deadline(string $state, array $order): ?array
    {
        return match ($state) {
            'placed' => ['seconds' => self::ACCEPT_WITHIN_SECONDS, 'transition' => 'expire'],
            'delivered' => ['seconds' => self::REVIEW_WITHIN_SECONDS, 'transition' => 'auto_complete'],
            default => null,
        };
    }

    public function allows(string $transition, array $order, App $app): bool
    {
        if ($transition === 'request_revision') {
            return $this->revisionsUsed($order) < (int) ($order['data']['revisions'] ?? 0);
        }

        return true;
    }

    public function orderFormTemplate(): string
    {
        return '@freelancer/order_form.twig';
    }

    public function orderFormData(array $offer, array $input, string $locale, App $app): array
    {
        $detail = $this->type->detailData($offer['id'], $locale, $app);
        $tiers = array_column($detail['packages'], 'tier');
        $tier = (int) ($input['package'] ?? 0);

        return [
            'currency' => $detail['currency'],
            'packages' => $detail['packages'],
            'extras' => $detail['extras'],
            'requirements' => $detail['requirements'],
            'selected_package' => in_array($tier, $tiers, true) ? $tier : ($tiers[0] ?? 0),
            'selected_extras' => array_map('intval', is_array($input['extras'] ?? null) ? $input['extras'] : []),
        ];
    }

    public function build(array $offer, array $input, string $locale, App $app): array
    {
        // Everything is read from the stored offer; the request only says
        // which package and which extras.
        $detail = $this->type->detailData($offer['id'], $locale, $app);
        $tier = (int) ($input['package'] ?? 0);

        $package = null;
        foreach ($detail['packages'] as $candidate) {
            if ($candidate['tier'] === $tier) {
                $package = $candidate;
            }
        }
        if ($package === null) {
            return ['items' => [], 'data' => [], 'errors' => ['freelancer.error.package']];
        }

        $items = [['label' => $package['name'], 'quantity' => 1, 'unit_price' => $package['price']]];
        $days = $package['delivery_days'];

        $chosen = array_unique(array_map('intval', is_array($input['extras'] ?? null) ? $input['extras'] : []));
        foreach ($chosen as $index) {
            $extra = $detail['extras'][$index] ?? null;
            if ($extra === null) {
                return ['items' => [], 'data' => [], 'errors' => ['freelancer.error.package']];
            }
            $items[] = ['label' => $extra['title'], 'quantity' => 1, 'unit_price' => $extra['price']];
            $days += $extra['extra_days'];
        }

        return [
            'items' => $items,
            'data' => ['tier' => $tier, 'delivery_days' => $days, 'revisions' => $package['revisions']],
            'errors' => [],
        ];
    }

    public function orderDetailTemplate(): string
    {
        return '@freelancer/order_detail.twig';
    }

    public function orderDetailData(array $order, string $locale, App $app): array
    {
        // The delivery time counts from the moment the provider accepted.
        $acceptedAt = null;
        foreach ($order['events'] ?? [] as $event) {
            if ($event['transition'] === 'accept') {
                $acceptedAt = $event['created_at'];
            }
        }
        $days = (int) ($order['data']['delivery_days'] ?? 0);
        $deliverBy = $acceptedAt !== null ? gmdate('Y-m-d H:i:s', strtotime($acceptedAt . ' UTC') + $days * 86400) : null;

        return [
            'delivery_days' => $days,
            'deliver_by' => $deliverBy,
            'overdue' => $order['state'] === 'in_progress' && $deliverBy !== null && $deliverBy < gmdate('Y-m-d H:i:s'),
            'revisions' => (int) ($order['data']['revisions'] ?? 0),
            'revisions_used' => $this->revisionsUsed($order),
        ];
    }

    private function revisionsUsed(array $order): int
    {
        return count(array_filter($order['events'] ?? [], fn (array $event) => $event['transition'] === 'request_revision'));
    }
}
