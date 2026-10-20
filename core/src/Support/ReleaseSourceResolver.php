<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Routes a package to the right ReleaseSource: a "license:<product>" repo
 * (Package\Packages::isLicensedRepo()) goes to the configured license
 * server, everything else goes to GitHub - except that, when a license
 * server is configured, an ordinary repository is first offered to it as
 * well (LicenseServerClient::latestPublic()), for the operator's own free
 * products it caches without any key (fewer installations hitting
 * GitHub's own rate limit directly). That is a pure speedup: anything
 * other than a well-formed release from it - not configured, unreachable,
 * repository not cached there - falls straight back to GitHub, exactly as
 * before this class offered that path at all.
 */
final class ReleaseSourceResolver implements ReleaseSource
{
    public function __construct(private ReleaseClient $github, private ?LicenseServerClient $license, private string $licenseServerUrl)
    {
    }

    public function latest(string $repo): array
    {
        if ($this->license !== null && !str_starts_with($repo, 'license:')) {
            return $this->license->latestPublic($repo) ?? $this->github->latest($repo);
        }

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
