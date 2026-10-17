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
            'products' => $catalog->fetch(),
            'purchase' => $purchase,
        ]);
    }

    public function buy(array $params): void
    {
        $app = $this->app;

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
