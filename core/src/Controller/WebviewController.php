<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Event\AccountLoggedIn;
use Modulento\Core\Support\WebviewHandoff;

/**
 * Where an app's embedded browser starts: the code the app got from the
 * mobile API becomes an ordinary session (see Support\WebviewHandoff).
 */
final class WebviewController extends Controller
{
    public function session(array $params): void
    {
        $handoff = (new WebviewHandoff($this->app->secrets))->verify($_GET['code'] ?? null);
        $account = $handoff !== null ? $this->app->accounts->findById($handoff['account_id']) : null;

        if ($handoff === null || $account === null || $account['status'] !== 'active') {
            http_response_code(403);
            $this->render('error.twig', ['status' => 403, 'message_key' => 'core.api.webview.invalid']);
            return;
        }

        $this->app->auth->login($handoff['account_id']);
        $this->app->events->dispatch(new AccountLoggedIn($handoff['account_id']));

        // The page named when the code was made, else the one named here.
        $path = $handoff['path'] ?? WebviewHandoff::localPath($_GET['redirect'] ?? null) ?? '/account';
        header('Cache-Control: no-store');
        $this->redirect($path, $this->app->locales->isEnabled($account['locale']) ? $account['locale'] : null);
    }
}
