<?php

declare(strict_types=1);

namespace Modulento\Example;

use Modulento\Core\App;
use Modulento\Core\Event\AccountExport;
use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Subscription\SubscriptionProvider;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Router;
use RuntimeException;

/**
 * Reference extension: uses every registration point once, so it doubles as
 * the template for a new extension and as the end-to-end check that the
 * interface works. It records logins in its own table and shows them in
 * the administration.
 */
final class Extension implements ExtensionContract
{
    /**
     * A real billing addon (modulento-ext-abos) would never swap its own
     * provider out like this - this exists so the core's own test suite can
     * register a provider of its choosing and see the whole mechanism (the
     * core's Subscriptions facade, Router::FEATURE, OfferController's
     * quotas, the catalogue's badge and placement bonus) work end to end,
     * without a real billing addon in the test fixture. Unset, this
     * extension still demonstrates the hook itself with a harmless one
     * that grants and limits nothing.
     */
    public static ?SubscriptionProvider $subscriptionProvider = null;

    public function register(Registrar $registrar): void
    {
        $registrar->permission('example.logins.view', 'example.permission.logins_view');

        $registrar->routes(function (Router $router): void {
            $router->get('/example', [ExampleController::class, 'index'], Router::PUBLIC);
            $router->get('/admin/example', [ExampleController::class, 'logins'], 'example.logins.view');
        });

        $registrar->adminMenu('example.admin.menu', '/admin/example', 'example.logins.view');
        $registrar->navigation('example.nav', '/example');
        $registrar->footerLink('example.footer_link', '/example');
        $registrar->homeSection('@example/home.twig', fn (App $app): array => ['site' => $app->siteName()]);
        $registrar->accountLink('example.account_link', '/example');
        $registrar->providerSection('@example/provider.twig', fn (array $provider, App $app): array => [
            'site' => $app->siteName(),
            'provider_name' => $provider['name'],
        ]);
        $registrar->offerSection('@example/offer.twig', fn (array $offer, App $app): array => [
            'site' => $app->siteName(),
            'offer_id' => $offer['id'],
        ]);
        $registrar->checkoutSection('@example/checkout.twig', fn (array $offer, array $input, App $app): array => [
            'offer_id' => $offer['id'],
        ]);
        // A real coupon addon (modulento-ext-coupons) would look the code up
        // against its own table; this exists only so the core's own test
        // suite can see Registrar::orderDiscount() work end to end - a 10%
        // discount for one fixed code, prorated across lines since
        // order_item.unit_price is unsigned (never a negative line).
        $registrar->orderDiscount(function (array $items, array $offer, array $input, App $app): array {
            $code = trim((string) ($input['example_discount_code'] ?? ''));
            if ($code === '') {
                return $items;
            }
            if ($code !== 'EXAMPLE10') {
                throw new RuntimeException('example.checkout.error.invalid_code');
            }

            return array_map(fn (array $item) => ['unit_price' => (int) round($item['unit_price'] * 0.9)] + $item, $items);
        });
        $registrar->subscriptionProvider(self::$subscriptionProvider ?? new class implements SubscriptionProvider {
            public function allows(int $accountId, string $feature): bool { return false; }
            public function grants(int $accountId, string $feature): bool { return false; }
            public function offerLimit(int $accountId, string $offerType): ?int { return 0; }
            public function imageLimit(int $accountId): ?int { return 0; }
            public function accountIdsWithFeature(string $feature): array { return []; }
            public function activePlans(): array { return []; }
            public function plans(): array { return []; }
            public function current(int $accountId): ?array { return null; }
            public function setQuantity(int $accountId, int $quantity): void { }
        });

        $registrar->listen(AccountLoggedIn::class, function (AccountLoggedIn $event, App $app): void {
            $stmt = $app->db->prepare('INSERT INTO x_example_login (account_id, logged_in_at) VALUES (:id, :now)');
            $stmt->execute(['id' => $event->accountId, 'now' => Clock::now()]);
        });

        $registrar->listen(AccountExport::class, function (AccountExport $event, App $app): void {
            $stmt = $app->db->prepare('SELECT logged_in_at FROM x_example_login WHERE account_id = :id ORDER BY id');
            $stmt->execute(['id' => $event->accountId]);
            $event->add('example', ['logins' => $stmt->fetchAll(\PDO::FETCH_COLUMN)]);
        });

        $registrar->task('example.prune-logins', 1440, function (App $app): void {
            $stmt = $app->db->prepare('DELETE FROM x_example_login WHERE logged_in_at < :before');
            $stmt->execute(['before' => Clock::now(-30 * 86400)]);
        });
    }
}
