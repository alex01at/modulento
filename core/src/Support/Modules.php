<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Functions of the core an operator can switch off without losing their
 * data: a switched-off module has no routes, no menu entries and no links,
 * and its tables stay as they are. Everything is on until switched off.
 */
final class Modules
{
    /** id => language key stem for name and description in the administration */
    public const ALL = [
        'reviews' => 'core.module.reviews',
        'contact' => 'core.module.contact',
        'withdrawal' => 'core.module.withdrawal',
        'reports' => 'core.module.reports',
        'avatars' => 'core.module.avatars',
        'remember_login' => 'core.module.remember_login',
        'subscriptions' => 'core.module.subscriptions',
        'inbox' => 'core.module.inbox',
    ];

    private const SETTING = 'core.modules_disabled';

    /** @var string[]|null */
    private ?array $disabled = null;

    public function __construct(private Settings $settings)
    {
    }

    public function enabled(string $id): bool
    {
        return isset(self::ALL[$id]) && !in_array($id, $this->disabled(), true);
    }

    /** @return string[] */
    public function disabled(): array
    {
        return $this->disabled ??= array_values(array_intersect(
            array_filter(explode(',', $this->settings->get(self::SETTING, ''))),
            array_keys(self::ALL)
        ));
    }

    /** @param string[] $enabledIds every module not named here is switched off */
    public function save(array $enabledIds): void
    {
        $this->disabled = array_values(array_diff(array_keys(self::ALL), $enabledIds));
        $this->settings->set(self::SETTING, implode(',', $this->disabled));
    }
}
