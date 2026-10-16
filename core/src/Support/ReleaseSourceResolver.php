<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Routes a package to the right ReleaseSource: a "license:<product>" repo
 * (Package\Packages::isLicensedRepo()) goes to the configured license
 * server, everything else goes to GitHub, exactly as before this class
 * existed. Implements ReleaseSource itself, so Package\Packages needs no
 * change beyond its constructor's type hint to support both at once.
 */
final class ReleaseSourceResolver implements ReleaseSource
{
    public function __construct(private ReleaseClient $github, private ?LicenseServerClient $license, private string $licenseServerUrl)
    {
    }

    public function latest(string $repo): array
    {
        return $this->forRepo($repo)->latest($repo);
    }

    public function checksum(string $url): string
    {
        return $this->forUrl($url)->checksum($url);
    }

    public function download(string $url, string $targetPath): void
    {
        $this->forUrl($url)->download($url, $targetPath);
    }

    private function forRepo(string $repo): ReleaseSource
    {
        return $this->license !== null && str_starts_with($repo, 'license:') ? $this->license : $this->github;
    }

    /** The source for a URL latest() itself returned - its own assets, never one a request could claim. */
    private function forUrl(string $url): ReleaseSource
    {
        return $this->license !== null && $this->licenseServerUrl !== '' && str_starts_with($url, $this->licenseServerUrl) ? $this->license : $this->github;
    }
}
