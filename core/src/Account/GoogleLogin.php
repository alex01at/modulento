<?php

declare(strict_types=1);

namespace Modulento\Core\Account;

use Modulento\Core\Support\HttpClient;

/**
 * Sign in with a Google account: the authorization-code flow a server
 * handles entirely on its own (no JavaScript on the login page needs to
 * know about it). HTTP goes through the core's own HttpClient, exactly as
 * a payment gateway's does - a test hands in a stand-in, never the network.
 */
final class GoogleLogin
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
        private HttpClient $http,
    ) {
    }

    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            // Without this, a returning visitor who already granted access
            // once is signed back in without ever seeing which account.
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Trades the code the callback received for who it belongs to.
     *
     * @return array{subject: string, email: string, email_verified: bool, name: ?string}|null null on any failure - a visitor sees one generic message either way
     */
    public function exchange(string $code): ?array
    {
        $tokenAnswer = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]));
        $token = $tokenAnswer['status'] === 200 ? json_decode($tokenAnswer['body'], true) : null;
        if (!is_array($token) || !is_string($token['access_token'] ?? null)) {
            return null;
        }

        $infoAnswer = $this->http->request('GET', self::USERINFO_URL, ['Authorization' => 'Bearer ' . $token['access_token']]);
        $info = $infoAnswer['status'] === 200 ? json_decode($infoAnswer['body'], true) : null;
        if (!is_array($info) || !is_string($info['sub'] ?? null) || !is_string($info['email'] ?? null)) {
            return null;
        }

        return [
            'subject' => $info['sub'],
            'email' => strtolower($info['email']),
            'email_verified' => (bool) ($info['email_verified'] ?? false),
            'name' => is_string($info['name'] ?? null) ? $info['name'] : null,
        ];
    }
}
