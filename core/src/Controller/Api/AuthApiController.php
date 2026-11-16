<?php

declare(strict_types=1);

namespace Modulento\Core\Controller\Api;

use Modulento\Core\Account\ApiTokens;
use Modulento\Core\Account\Credentials;
use Modulento\Core\App;
use Modulento\Core\Support\WebviewHandoff;
use RuntimeException;

final class AuthApiController extends ApiController
{
    /** What Credentials::verify() refuses with, as the API says it. */
    private const ERRORS = [
        'core.login.too_many_attempts' => ['too_many_attempts', 'core.api.error.too_many_attempts', 429],
        'core.login.unverified' => ['email_unverified', 'core.api.error.email_unverified', 403],
    ];

    /** E-mail address and password for a token. The token is shown this once. */
    public function login(array $params): void
    {
        $result = Credentials::verify($this->app, $this->input('email') ?? '', $this->input('password') ?? '');
        if (!$result['ok']) {
            [$code, $messageKey, $status] = self::ERRORS[$result['error_key']]
                ?? ['invalid_credentials', 'core.api.error.invalid_credentials', 401];
            $this->fail($code, $messageKey, $status);
            return;
        }

        $account = $result['account'];
        $token = $this->app->apiTokens->issue((int) $account['id'], $this->input('device_name'));

        $this->data([
            'token' => $token['selector'] . '.' . $token['secret'],
            'account' => self::accountData($account),
            'api_version' => App::API_VERSION,
        ]);
    }

    /** Revokes the token this request was sent with - the other devices of the account stay logged in. */
    public function logout(array $params): void
    {
        $token = ApiTokens::parseHeader($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        $accountId = (int) $this->app->auth->account()['id'];

        if ($token === null || !$this->app->apiTokens->revokeBySelector($token['selector'], $accountId)) {
            $this->fail('invalid_token', 'core.api.error.invalid_token', 400);
            return;
        }

        // The session this request started ends with the token, so a
        // client that keeps cookies is logged out as well.
        $this->app->auth->logout();
        $this->data(['ok' => true]);
    }

    /**
     * A code that logs an embedded browser in as this account, for the
     * pages the API has no endpoint for (see Support\WebviewHandoff).
     */
    public function webviewHandoff(array $params): void
    {
        try {
            $code = (new WebviewHandoff($this->app->secrets))->issue(
                (int) $this->app->auth->account()['id'],
                $this->input('path')
            );
        } catch (RuntimeException) {
            $this->fail('unavailable', 'core.api.error.unavailable', 503);
            return;
        }

        $this->data([
            'code' => $code,
            'url' => $this->app->url('/webview/session', absolute: true) . '?code=' . $code,
            'expires_in' => WebviewHandoff::TTL_SECONDS,
        ]);
    }
}
