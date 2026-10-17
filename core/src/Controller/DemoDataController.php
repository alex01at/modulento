<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Catalogue\OfferImages;
use Modulento\Core\Support\DemoData;
use Modulento\Core\Support\Session;

/** Administration → Demo data: accounts, provider profiles, requests and offers to look at, removable in one step. */
final class DemoDataController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/demo_data.twig', [
            'counts' => (new DemoData($this->app))->counts(),
            'max_providers' => DemoData::MAX_PROVIDERS,
            'max_customers' => DemoData::MAX_CUSTOMERS,
            'max_offers_per_provider' => DemoData::MAX_OFFERS_PER_PROVIDER,
            'images_available' => OfferImages::available(),
        ]);
    }

    public function generate(array $params): void
    {
        $generated = (new DemoData($this->app))->generate(
            (int) ($_POST['providers'] ?? 4),
            (int) ($_POST['customers'] ?? 2),
            (int) ($_POST['offers_per_provider'] ?? 2),
            isset($_POST['with_images'])
        );
        Session::flash($generated ? 'success' : 'error', $this->trans($generated ? 'core.admin.demo_data.generated' : 'core.admin.demo_data.already_there'));
        $this->redirect('/admin/demo-data');
    }

    public function clear(array $params): void
    {
        (new DemoData($this->app))->clear();
        Session::flash('success', $this->trans('core.admin.demo_data.cleared'));
        $this->redirect('/admin/demo-data');
    }
}
