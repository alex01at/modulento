<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Content\CustomMenu;

final class HomeController extends Controller
{
    /**
     * core.home_redirect: an internal path another page (an extension's own,
     * e.g.) can be made the start page with, without the core knowing that
     * page or its extension at all - set by whatever offers "use this as
     * the start page" (there is no settings field of its own for it yet).
     */
    private const REDIRECT_SETTING = 'core.home_redirect';

    public function index(array $params): void
    {
        $redirect = $this->app->settings->get(self::REDIRECT_SETTING);
        if ($redirect !== '' && str_starts_with($redirect, '/') && CustomMenu::validPath($redirect)) {
            $this->redirect($redirect);
            return;
        }

        $this->render('home.twig');
    }
}
