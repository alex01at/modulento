<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * Minimal synchronous event dispatcher. An event is a plain object;
 * listeners subscribe to its class name. This is the only way an extension
 * reacts to something that happens in the core or in another extension.
 */
final class Events
{
    /** @var array<class-string, callable[]> */
    private array $listeners = [];

    /** @param class-string $eventClass */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
