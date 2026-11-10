<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\HomeLayout;
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
        // Every block's own background and/or text colour/shadow - the home
        // page's and every normal page's alike - is served together with the
        // design values - one stylesheet every theme already loads on every
        // page, and the one place inline style is allowed to live under this
        // site's CSP.
        $app = $this->app;
        $extra = $app->pageBlockTypes();
        echo $app->design->css()
            . HomeLayout::cssFor($app->homeLayout->blocks($extra))
            . $app->pages->blockCss($extra)
            . self::maintenanceCss($app->settings->get('core.maintenance_background'));
    }

    /**
     * The maintenance page's own background picture, if one is set - same
     * "no inline style" reasoning as every block's own. A dark overlay and
     * white text come bundled, not a separate setting: a photo background
     * needs one or the other to stay legible regardless of what is in the
     * picture (the same lesson a block's own text colour/shadow exists for),
     * and an operator who only wants a scenic photo and nothing else can
     * already get that from a block's own background, which has no overlay.
     */
    public static function maintenanceCss(string $background): string
    {
        $background = HomeLayout::cleanMedia($background);

        return $background === '' ? '' : ".maintenance-page {\n"
            . "    background-image: linear-gradient(rgb(0 0 0 / .45), rgb(0 0 0 / .45)), url({$background});\n"
            . "    background-size: cover; background-position: center; color: #fff;\n"
            . "}\n.maintenance-page a { color: #fff; }\n";
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
