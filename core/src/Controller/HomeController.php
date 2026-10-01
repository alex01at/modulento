<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

final class HomeController extends Controller
{
    public function index(array $params): void
    {
        $this->render('home.twig');
    }
}
