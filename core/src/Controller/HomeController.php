<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;
use Modulento\Core\Support\Translator;

final class HomeController extends Controller
{
    public function index(array $params): void
    {
        $this->render('home.twig');
    }

    public function switchLocale(array $params): void
    {
        $locale = $_POST['locale'] ?? '';
        if (in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            Session::set('locale', $locale);
        }

        $this->redirect('/');
    }
}
