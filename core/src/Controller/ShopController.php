<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\ShopCatalog;
use RuntimeException;

/**
 * Administration -> Shop: browse what the configured license server
 * sells, and buy a product straight from here through Stripe Checkout -
 * the license it produces is entered under Administration -> Extensions
 * and themes automatically, no copy-pasting a key.
 */
final class ShopController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $catalog = $this->catalog();

        $purchase = null;
        $reference = (string) ($_GET['purchase'] ?? '');
        if ($reference !== '') {
            $status = $catalog->checkStatus($reference);
            if ($status['status'] === 'completed' && $status['license_key'] !== null && $status['product'] !== null) {
                $app->licenseKeys->save($status['product'], $status['license_key']);
            }
            $purchase = $status + ['reference' => $reference];
        }

        $this->render('@admin/shop.twig', [
            'enabled' => (string) ($app->config['update']['license_server'] ?? '') !== '',
            'products' => array_values(array_filter($catalog->fetch(), fn (array $product): bool => !$this->isInstalled($product['id']))),
            'purchase' => $purchase,
        ]);
    }

    /**
     * Whether a catalog product is already installed - by the naming
     * convention the operator's own product ids follow (see
     * Package\Packages::OFFICIAL and the license server's own README):
     * "ext-<id>" for an extension/app, a bare theme id otherwise. The
     * catalog never carries the GitHub repo itself (Support\ShopCatalog
     * deliberately only exposes what a buyer needs to see), so this is
     * the only signal available here to begin with.
     */
    private function isInstalled(string $productId): bool
    {
        $app = $this->app;
        [$kind, $id] = str_starts_with($productId, 'ext-')
            ? ['extension', substr($productId, 4)]
            : ['theme', $productId];

        return $app->packages->find($kind, $id) !== null;
    }

    public function buy(array $params): void
    {
        $app = $this->app;

        // Defense in depth: the catalog itself already leaves out anything
        // installed (index() above), but this is a plain POST a crafted
        // request could still reach directly.
        if ($this->isInstalled($params['product'])) {
            Session::flash('error', $this->trans('core.admin.shop.error.already_installed'));
            $this->redirect('/admin/shop');
            return;
        }

        try {
            $result = $this->catalog()->startPurchase(
                $params['product'],
                (string) ($app->config['app']['url'] ?? ''),
                $app->url('/admin/shop', absolute: true) . '?purchase={reference}',
                $app->url('/admin/shop', absolute: true)
            );
        } catch (RuntimeException $e) {
            Session::flash('error', $this->trans($e->getMessage()));
            $this->redirect('/admin/shop');
            return;
        }

        header('Location: ' . $result['checkout_url']);
    }

    private function catalog(): ShopCatalog
    {
        $app = $this->app;
        $licenseServerUrl = (string) ($app->config['update']['license_server'] ?? '');
        $http = $app->config['update']['license_http'] ?? new CurlHttpClient();

        return new ShopCatalog($http, $licenseServerUrl);
    }
}
