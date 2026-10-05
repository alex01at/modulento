<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Design;
use Modulento\Core\Support\Session;

/** The design values: the stylesheet they make, and their form in the administration. */
final class DesignController extends Controller
{
    /** The stylesheet of the current values. Its name changes with the values, so caches follow. */
    public function stylesheet(array $params): void
    {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo $this->app->design->css();
    }

    public function index(array $params): void
    {
        $design = $this->app->design;
        $this->render('@admin/design.twig', [
            'values' => $design->values(),
            'fonts' => array_keys(Design::FONTS),
            'customized' => $design->isCustomized(),
        ]);
    }

    public function save(array $params): void
    {
        $refused = $this->app->design->save($_POST);
        if ($refused !== []) {
            Session::flash('error', $this->trans('core.design.refused', ['values' => implode(', ', $refused)]));
        } else {
            Session::flash('success', $this->trans('core.design.saved'));
        }
        $this->redirect('/admin/design');
    }

    public function reset(array $params): void
    {
        $this->app->design->reset();
        Session::flash('success', $this->trans('core.design.reset_done'));
        $this->redirect('/admin/design');
    }
}
