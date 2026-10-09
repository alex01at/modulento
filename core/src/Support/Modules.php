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
        'notifications' => 'core.module.notifications',
        'requests' => 'core.module.requests',
    ];

    private const SETTING = 'core.modules_disabled';

    /** @var array<string, string> id => language key stem, modules an extension brought along */
    private array $extra = [];
    /** @var string[]|null */
    private ?array $disabled = null;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * An optional feature of an extension, switched off and on the same way
     * as a core module. Only exists while that extension is active - called
     * from its Extension::register(), nothing calls it otherwise.
     */
    public function register(string $id, string $labelKey): void
    {
        $this->extra[$id] = $labelKey;
        // A module registered after disabled() was already read (core's own
        // modules are checked during Kernel::registerCore(), before
        // extensions load) must not be judged against a stale, narrower
        // list of known ids.
        $this->disabled = null;
    }

    /** @return array<string, string> id => language key stem, core modules first */
    public function all(): array
    {
        return self::ALL + $this->extra;
    }

    public function enabled(string $id): bool
    {
        return isset($this->all()[$id]) && !in_array($id, $this->disabled(), true);
    }

    /** @return string[] */
    public function disabled(): array
    {
        return $this->disabled ??= array_values(array_intersect(
            array_filter(explode(',', $this->settings->get(self::SETTING, ''))),
            array_keys($this->all())
        ));
    }

    /** @param string[] $enabledIds every module not named here is switched off */
    public function save(array $enabledIds): void
    {
        $this->disabled = array_values(array_diff(array_keys($this->all()), $enabledIds));
        $this->settings->set(self::SETTING, implode(',', $this->disabled));
    }
}
