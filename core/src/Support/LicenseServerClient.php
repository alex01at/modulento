<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use Modulento\Core\License\LicenseKeys;

/**
 * Releases of a product an operator sells under its own license, gated by a
 * license server the operator runs (not this installation, and not GitHub).
 * The license key never travels with the package's own zip - the server
 * decides whether this domain, with this key, gets a release at all, and
 * only then hands back a short-lived download address of its own.
 *
 * $repo is "license:<product>" (see Package\Packages::isLicensedRepo()); the
 * part after the colon is sent as the product id. There is one client per
 * installation, configured with the server's own base address; which key to
 * send for which product comes from License\LicenseKeys, looked up by
 * product id at the moment of the call - a key an operator never entered
 * simply answers as "no license for this product".
 */
final class LicenseServerClient implements ReleaseSource
{
    public function __construct(
        private HttpClient $http,
        private string $serverUrl,
        private string $domain,
        private LicenseKeys $keys
    ) {
    }

    public function latest(string $repo): array
    {
        $product = self::productOf($repo);
        $key = $this->keys->find($product);
        if ($key === null || $key === '') {
            throw new UpdateException('core.update.error.license_missing', ['product' => $product]);
        }

        $response = $this->http->request(
            'POST',
            rtrim($this->serverUrl, '/') . '/v1/releases/latest',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['license_key' => $key, 'product' => $product, 'domain' => $this->domain], JSON_UNESCAPED_SLASHES)
        );

        $body = json_decode($response['body'], true);

        if ($response['status'] !== 200) {
            $error = is_array($body) ? (string) ($body['error'] ?? '') : '';
            $errorKey = self::errorKey($error);

            throw new UpdateException($errorKey, $errorKey === 'core.update.error.unreachable' ? ['status' => $response['status']] : ['product' => $product]);
        }

        $version = is_array($body) && is_string($body['version'] ?? null) ? $body['version'] : '';
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UpdateException('core.update.error.invalid_response');
        }

        $assets = [];
        foreach ($body['assets'] ?? [] as $name => $url) {
            if (is_string($name) && is_string($url)) {
                $assets[$name] = $url;
            }
        }

        return [
            'version' => $version,
            'published_at' => (string) ($body['published_at'] ?? ''),
            'changelog' => (string) ($body['changelog'] ?? ''),
            'assets' => $assets,
        ];
    }

    public function checksum(string $url): string
    {
        $response = $this->http->request('GET', $url, ['Accept' => 'text/plain']);

        if ($response['status'] !== 200 || preg_match('/^([0-9a-f]{64})\b/i', trim($response['body']), $matches) !== 1) {
            throw new UpdateException('core.update.error.download', ['reason' => 'sha256, HTTP ' . $response['status']]);
        }

        return strtolower($matches[1]);
    }

    public function download(string $url, string $targetPath): void
    {
        $response = $this->http->request('GET', $url, ['Accept' => 'application/octet-stream']);

        if ($response['status'] !== 200 || file_put_contents($targetPath, $response['body']) === false) {
            @unlink($targetPath);
            throw new UpdateException('core.update.error.download', ['reason' => 'HTTP ' . $response['status']]);
        }
    }

    /** The product id a "license:<product>" repo string names. */
    public static function productOf(string $repo): string
    {
        return substr($repo, strlen('license:'));
    }

    private static function errorKey(string $error): string
    {
        return match ($error) {
            'expired' => 'core.update.error.license_expired',
            'domain_mismatch' => 'core.update.error.license_domain',
            'revoked' => 'core.update.error.license_revoked',
            'not_found' => 'core.update.error.license_unknown',
            default => 'core.update.error.unreachable',
        };
    }
}
