<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Where a package's (core's, an extension's, a theme's) releases come from.
 * ReleaseClient is the only implementation most installations ever need -
 * GitHub Releases of a public or token-readable private repository.
 * LicenseServerClient is the other one: for a package an operator sells
 * under its own license (a "license:<product>" repo string, see
 * Package\Packages::isLicensedRepo()), update access is gated by a license
 * server instead of a GitHub token, so the gate is enforced by
 * infrastructure the operator controls, not by anything shipped to the
 * customer's installation.
 */
interface ReleaseSource
{
    /**
     * @return array{version: string, published_at: string, changelog: string, assets: array<string, string>}
     *         assets: file name => address to download it from
     * @throws UpdateException
     */
    public function latest(string $repo): array;

    /** The SHA-256 a ".sha256" asset states ("sha256sum" format: the hash, then the file name). @throws UpdateException */
    public function checksum(string $url): string;

    /** Downloads an asset to $targetPath. @throws UpdateException */
    public function download(string $url, string $targetPath): void;
}
