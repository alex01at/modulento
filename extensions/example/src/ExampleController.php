<?php

declare(strict_types=1);

namespace Modulento\Example;

use Modulento\Core\Controller\Controller;

final class ExampleController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@example/index.twig');
    }

    public function logins(array $params): void
    {
        $logins = $this->app->db
            ->query('SELECT account_id, logged_in_at FROM x_example_login ORDER BY id DESC LIMIT 20')
            ->fetchAll();

        $this->render('@example/logins.twig', ['logins' => $logins]);
    }
}
