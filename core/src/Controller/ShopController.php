<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\Migrator;
use Modulento\Core\Support\Session;
use Modulento\Core\Support\ShopCatalog;
use Modulento\Core\Support\UpdateException;
use RuntimeException;

/**
 * Administration -> Shop: browse what the configured license server
 * sells, and buy a product straight from here through Stripe Checkout -
 * the license it produces is entered under Administration -> Extensions
 * and themes automatically, no copy-pasting a key.
 *
 * A master license (one key, valid for every product the license server
 * sells, not just one - see License\LicenseKeys::saveMaster()) skips
 * Stripe entirely: install() below copies it under the specific product
 * id being installed and runs the ordinary "license:<id>" install flow,
 * exactly as if that key had been entered by hand under Administration ->
 * Extensions and themes first.
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

        $masterKey = $app->licenseKeys->findMaster();

        $this->render('@admin/shop.twig', [
            'enabled' => (string) ($app->config['update']['license_server'] ?? '') !== '',
            'products' => array_values(array_filter($catalog->fetch(), fn (array $product): bool => !$this->isInstalled($product['id']))),
            'purchase' => $purchase,
            'master_license' => $masterKey !== null ? '••••' . substr($masterKey, -4) : null,
        ]);
    }

    /** Stores (or replaces) the master license key - no product id of its own, see License\LicenseKeys::saveMaster(). */
    public function saveMasterLicense(array $params): void
    {
        $key = trim((string) ($_POST['license_key'] ?? ''));
        if ($key === '') {
            Session::flash('error', $this->trans('core.license.error.invalid'));
        } else {
            $this->app->licenseKeys->saveMaster($key);
            Session::flash('success', $this->trans('core.admin.shop.master_saved'));
        }

        $this->redirect('/admin/shop');
    }

    public function removeMasterLicense(array $params): void
    {
        $this->app->licenseKeys->removeMaster();
        Session::flash('success', $this->trans('core.admin.shop.master_removed'));
        $this->redirect('/admin/shop');
    }

    /**
     * Installs a product straight from the master license, no Stripe
     * involved: copies the master key under this product's own id (so it
     * shows up and keeps working under Administration -> Extensions and
     * themes exactly like one entered by hand) and installs
     * "license:<id>" the same way PackageController::install() does.
     */
    public function install(array $params): void
    {
        $app = $this->app;
        $productId = $params['product'];

        if ($this->isInstalled($productId)) {
            Session::flash('error', $this->trans('core.admin.shop.error.already_installed'));
            $this->redirect('/admin/shop');
            return;
        }

        $masterKey = $app->licenseKeys->findMaster();
        if ($masterKey === null) {
            Session::flash('error', $this->trans('core.admin.shop.error.no_master'));
            $this->redirect('/admin/shop');
            return;
        }
        $app->licenseKeys->save($productId, $masterKey);

        @set_time_limit(0);
        try {
            $result = $app->packages->install('license:' . $productId);
            if ($result['kind'] === 'extension') {
                Migrator::runAll($app->db, $app->config['app']['root']);
            }
            Session::flash('success', $this->trans('core.package.installed.' . $result['kind'], ['id' => $result['id'], 'version' => $result['version']]));
        } catch (UpdateException $e) {
            Session::flash('error', $this->trans($e->messageKey, $e->params));
        }

        $this->redirect('/admin/shop');
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
