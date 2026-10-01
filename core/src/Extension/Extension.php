<?php

declare(strict_types=1);

namespace Modulento\Core\Extension;

/**
 * The entry class of an extension. register() runs on every request (web
 * and CLI) while the extension is enabled, so it must only announce things
 * to the Registrar and never do work of its own.
 *
 * Rules that also apply to first-party extensions:
 *  - core tables are only accessed through core classes, never by SQL;
 *    a foreign key to a core primary key is the one allowed reference
 *  - own tables are named x_<extension id>_<name>
 *  - core files are never edited; use events, own templates or a theme
 */
interface Extension
{
    public function register(Registrar $registrar): void;
}
