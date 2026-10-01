<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Reads the releases of a GitHub repository and downloads their assets -
 * for the core's own updates and for packages (extensions and themes).
 * A release is a tag "v<version>" with a zip and the matching ".sha256".
 */
final class ReleaseClient
{
    private const API = 'https://api.github.com';

    public function __construct(private string $token)
    {
    }

    public static function isRepository(string $repo): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1;
    }

    /**
     * @return array{version: string, published_at: string, changelog: string, assets: array<string, string>}
     *         assets: file name => address to download it from
     * @throws UpdateException
     */
    public function latest(string $repo): array
    {
        [$status, $body] = $this->get(self::API . '/repos/' . $repo . '/releases/latest', 'application/vnd.github+json');

        if ($status === 404) {
            throw new UpdateException('core.update.error.no_release', ['repo' => $repo]);
        }
        if ($status !== 200) {
            throw new UpdateException('core.update.error.unreachable', ['status' => $status]);
        }

        $release = json_decode($body, true);
        $version = is_array($release) && is_string($release['tag_name'] ?? null) ? ltrim($release['tag_name'], 'v') : '';
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new UpdateException('core.update.error.invalid_response');
        }

        $assets = [];
        foreach ($release['assets'] ?? [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['url'] ?? null)) {
                $assets[$asset['name']] = $asset['url'];
            }
        }

        return [
            'version' => $version,
            'published_at' => (string) ($release['published_at'] ?? ''),
            'changelog' => (string) ($release['body'] ?? ''),
            'assets' => $assets,
        ];
    }

    /** The SHA-256 a ".sha256" asset states ("sha256sum" format: the hash, then the file name). */
    public function checksum(string $url): string
    {
        [$status, $body] = $this->get($url, 'application/octet-stream');

        if ($status !== 200 || preg_match('/^([0-9a-f]{64})\b/i', trim($body), $matches) !== 1) {
            throw new UpdateException('core.update.error.download', ['reason' => 'sha256, HTTP ' . $status]);
        }

        return strtolower($matches[1]);
    }

    /** Downloads an asset to $targetPath. */
    public function download(string $url, string $targetPath): void
    {
        $fh = fopen($targetPath, 'wb');
        if ($fh === false) {
            throw new UpdateException('core.update.error.not_writable', ['path' => basename(dirname($targetPath))]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions('application/octet-stream', 300) + [CURLOPT_FILE => $fh]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status !== 200) {
            @unlink($targetPath);
            throw new UpdateException('core.update.error.download', ['reason' => $error !== '' ? $error : 'HTTP ' . $status]);
        }
    }

    /** @return array{0: int, 1: string} HTTP status (0 if the request failed) and body */
    private function get(string $url, string $accept): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions($accept, 20) + [CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [is_string($body) ? $status : 0, is_string($body) ? $body : ''];
    }

    /** @return array<int, mixed> curl options shared by every request */
    private function curlOptions(string $accept, int $timeout): array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: Modulento-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        // Only needed for a private repository: a fine-grained token
        // with read-only "Contents" access to it.
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            // This connection delivers executable application code.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // An asset download answers with a redirect to GitHub's file
            // storage. curl drops the Authorization header when the host
            // changes, so the token never leaves api.github.com.
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
        ];
    }
}
