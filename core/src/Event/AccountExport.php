<?php

declare(strict_types=1);

namespace Modulento\Core\Event;

/**
 * Dispatched when someone downloads their own data. Every extension that
 * stores personal data about accounts listens and adds its part, so the
 * export is complete without the core knowing the extension's tables.
 */
final class AccountExport
{
    /** @var array<string, mixed> */
    private array $sections = [];

    public function __construct(public readonly int $accountId)
    {
    }

    /** @param string $section "core" or the extension id */
    public function add(string $section, mixed $data): void
    {
        $this->sections[$section] = $data;
    }

    /** @return array<string, mixed> */
    public function sections(): array
    {
        return $this->sections;
    }
}
