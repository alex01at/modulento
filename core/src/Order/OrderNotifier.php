<?php

declare(strict_types=1);

namespace Modulento\Core\Order;

use Modulento\Core\App;
use Modulento\Core\Event\OrderStateChanged;
use Modulento\Core\Support\Money;

/**
 * Tells the people an order concerns what happened to it, each by e-mail
 * in their own language, and tells extensions through an event.
 */
final class OrderNotifier
{
    /**
     * @param array|null $before the order before the transition (null for a new order)
     * @param string $role who applied it: buyer, provider, admin or system
     */
    public static function stateChanged(App $app, ?array $before, array $after, string $transition, string $role, ?string $note): void
    {
        $app->events->dispatch(new OrderStateChanged($after['id'], $before['state'] ?? null, $after['state'], $transition, $role));

        $flow = $app->orders->flow($after['flow']);
        if ($flow === null) {
            return;
        }

        $labelKey = $app->orders->eventLabel($after, $transition);
        $stateKey = $flow->states()[$after['state']]['label'] ?? $after['state'];

        // The one who acted does not need a mail about it; what the
        // system or an administrator did concerns both sides.
        foreach (self::recipients($app, $after) as $side => $recipient) {
            if ($side === $role) {
                continue;
            }

            $locale = $recipient['locale'];
            $app->mailer->send($recipient['email'], 'emails/order_update.txt.twig', [
                'number' => $after['number'],
                'title' => $after['offer_title'],
                'event' => $app->translator->trans($labelKey, [], $locale),
                'state' => $app->translator->trans($stateKey, [], $locale),
                'actor' => $app->translator->trans('core.order.role.' . $role, [], $locale),
                'note' => $note ?? '',
                'link' => $app->url('/orders/' . $after['id'], $locale, true),
            ], $locale);
            $app->notifications->create($recipient['id'], 'order_state', 'core.notification.order_state', [
                'number' => $after['number'],
                'title' => $after['offer_title'],
                'event' => $app->translator->trans($labelKey, [], $locale),
            ], '/orders/' . $after['id']);
        }
    }

    public static function message(App $app, array $order, string $role, string $body): void
    {
        foreach (self::recipients($app, $order) as $side => $recipient) {
            if ($side === $role) {
                continue;
            }

            $app->mailer->send($recipient['email'], 'emails/order_message.txt.twig', [
                'number' => $order['number'],
                'title' => $order['offer_title'],
                'sender' => $role === 'buyer' ? $order['buyer_name'] : $order['provider_name'],
                'message' => $body,
                'link' => $app->url('/orders/' . $order['id'], $recipient['locale'], true),
            ], $recipient['locale']);
            $app->notifications->create($recipient['id'], 'order_message', 'core.notification.order_message', [
                'number' => $order['number'],
                'title' => $order['offer_title'],
            ], '/orders/' . $order['id']);
        }
    }

    /**
     * A payment service reported the order as paid. Both sides are told:
     * neither of them did it by hand, and the provider may be waiting for
     * it before starting.
     */
    public static function paid(App $app, array $order): void
    {
        $labelKey = ($app->orders->paymentMethods()[$order['payment_method']] ?? null)?->labelKey();

        foreach (self::recipients($app, $order) as $recipient) {
            $locale = $recipient['locale'];
            $app->mailer->send($recipient['email'], 'emails/order_paid.txt.twig', [
                'number' => $order['number'],
                'title' => $order['offer_title'],
                'amount' => Money::format($order['total'], $order['currency'], $locale),
                'method' => $labelKey !== null ? $app->translator->trans($labelKey, [], $locale) : $order['payment_method'],
                'link' => $app->url('/orders/' . $order['id'], $locale, true),
            ], $locale);
        }
    }

    /** @return array<string, array{id: int, email: string, locale: string}> "buyer" and "provider", as far as their accounts still exist */
    private static function recipients(App $app, array $order): array
    {
        $recipients = [];
        $locale = fn (string $wanted) => $app->locales->isEnabled($wanted) ? $wanted : $app->locales->default();

        $buyer = $order['buyer_id'] !== null ? $app->accounts->findById($order['buyer_id']) : null;
        if ($buyer !== null && $buyer['status'] === 'active') {
            $recipients['buyer'] = ['id' => (int) $buyer['id'], 'email' => $buyer['email'], 'locale' => $locale($buyer['locale'])];
        }

        $provider = $order['provider_id'] !== null ? $app->providers->find($order['provider_id']) : null;
        if ($provider !== null && $provider['account_status'] === 'active') {
            $recipients['provider'] = ['id' => (int) $provider['account_id'], 'email' => $provider['account_email'], 'locale' => $locale($provider['account_locale'])];
        }

        return $recipients;
    }
}
