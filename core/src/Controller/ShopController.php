<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\CurlHttpClient;
use Modulento\Core\Support\ShopCatalog;

/** Administration -> Shop: browse what the configured license server sells, bought and installed from here later on. */
final class ShopController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $licenseServerUrl = (string) ($app->config['update']['license_server'] ?? '');
        $http = $app->config['update']['license_http'] ?? new CurlHttpClient();
        $catalog = new ShopCatalog($http, $licenseServerUrl);

        $this->render('@admin/shop.twig', [
            'enabled' => $licenseServerUrl !== '',
            'products' => $catalog->fetch(),
        ]);
    }
}
