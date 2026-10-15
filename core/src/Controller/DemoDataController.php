<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\DemoData;
use Modulento\Core\Support\Session;

/** Administration → Demo data: accounts, provider profiles, requests and offers to look at, removable in one step. */
final class DemoDataController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@admin/demo_data.twig', ['counts' => (new DemoData($this->app))->counts()]);
    }

    public function generate(array $params): void
    {
        $generated = (new DemoData($this->app))->generate();
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
