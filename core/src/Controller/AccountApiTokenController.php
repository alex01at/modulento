<?php

declare(strict_types=1);

namespace Modulento\Core\Controller;

use Modulento\Core\Support\Session;

/**
 * "My app logins": the devices on which an app is logged in to the account
 * through the mobile API, and the button that logs one of them out.
 */
final class AccountApiTokenController extends Controller
{
    public function index(array $params): void
    {
        $this->render('account/api-tokens.twig', [
            'tokens' => $this->app->apiTokens->list((int) $this->app->auth->account()['id']),
        ]);
    }

    public function revoke(array $params): void
    {
        $revoked = $this->app->apiTokens->revoke((int) $params['id'], (int) $this->app->auth->account()['id']);

        Session::flash(
            $revoked ? 'success' : 'error',
            $this->trans($revoked ? 'core.api.tokens.revoked' : 'core.api.tokens.not_found')
        );
        $this->redirect('/account/api-tokens');
    }
}
