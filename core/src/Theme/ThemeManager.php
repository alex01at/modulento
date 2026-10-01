<?php

declare(strict_types=1);

namespace Modulento\Core\Theme;

use Modulento\Core\Support\Settings;

/**
 * The core contains no templates. Every page is rendered by a theme, a
 * folder below themes/ with theme.json, templates/ and assets/:
 *
 *  - "default" is the complete site theme shipped with every release. Any
 *    other site theme only needs the templates it wants to change; what it
 *    leaves out is taken from "default".
 *  - "admin" renders the administration and is deliberately separate, so
 *    a broken or half-finished site theme can never lock anyone out of it.
 */
final class ThemeManager
{
    public const DEFAULT_THEME = 'default';
    public const ADMIN_THEME = 'admin';
    public const SETTING = 'core.theme';

    /** @var array<string, array{id: string, name: string, version: string, dir: string}>|null */
    private ?array $themes = null;

    public function __construct(private string $themesDir, private Settings $settings)
    {
    }

    /** @return array<string, array{id: string, name: string, version: string, dir: string}> every valid theme folder */
    public function discover(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }

        $this->themes = [];
        foreach (glob($this->themesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $data = is_file($dir . '/theme.json') ? json_decode((string) file_get_contents($dir . '/theme.json'), true) : null;
            $id = basename($dir);

            if (!is_array($data) || ($data['id'] ?? null) !== $id || !preg_match('/^[a-z][a-z0-9_]{1,31}$/', $id)) {
                continue;
            }

            $this->themes[$id] = [
                'id' => $id,
                'name' => is_string($data['name'] ?? null) ? $data['name'] : $id,
                'version' => is_string($data['version'] ?? null) ? $data['version'] : '',
                'dir' => $dir,
            ];
        }
        ksort($this->themes);

        return $this->themes;
    }

    /** @return array<string, array{id: string, name: string, version: string, dir: string}> themes selectable for the site */
    public function siteThemes(): array
    {
        return array_diff_key($this->discover(), [self::ADMIN_THEME => true]);
    }

    /** The chosen site theme, or "default" if none is chosen or its folder is gone. */
    public function active(): string
    {
        $chosen = $this->settings->get(self::SETTING, self::DEFAULT_THEME);

        return isset($this->siteThemes()[$chosen]) ? $chosen : self::DEFAULT_THEME;
    }

    public function activate(string $id): bool
    {
        if (!isset($this->siteThemes()[$id])) {
            return false;
        }

        $this->settings->set(self::SETTING, $id);

        return true;
    }

    /**
     * Folders to search, first match wins: the active theme, then
     * "default".
     *
     * @param string $subDir "templates", "assets" or "extensions/<id>"
     * @return string[] existing directories only
     */
    public function siteDirs(string $subDir): array
    {
        $dirs = [];
        foreach (array_unique([$this->active(), self::DEFAULT_THEME]) as $id) {
            $dir = $this->themesDir . '/' . $id . '/' . $subDir;
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    public function adminDir(string $subDir): string
    {
        return $this->themesDir . '/' . self::ADMIN_THEME . '/' . $subDir;
    }
}
