<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Unpacks a downloaded zip without letting it write outside its folder.
 *
 * Every entry name is validated before anything is extracted, and an entry
 * whose Unix mode marks it as a symlink is rejected - a path check on the
 * name alone would not catch a symlink that points outside the extraction
 * directory under a harmless-looking name.
 */
final class SafeArchive
{
    /** @throws UpdateException */
    public static function extract(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('core.update.error.archive');
        }

        $safeNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            // The mode bits only mean what they look like when the entry
            // was written on Unix, which every package built by a release
            // workflow is. Anything else is rejected outright.
            if (!self::isSafeEntryName($name)
                || !$zip->getExternalAttributesIndex($i, $opsys, $attr)
                || $opsys !== ZipArchive::OPSYS_UNIX
                || (((int) $attr >> 16) & 0xF000) === 0xA000) {
                $zip->close();
                throw new UpdateException('core.update.error.unsafe_entry', ['name' => $name]);
            }

            $safeNames[] = $name;
        }

        self::removeDir($extractDir);
        if (!@mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new UpdateException('core.update.error.not_writable', ['path' => basename($extractDir)]);
        }

        $extracted = $zip->extractTo($extractDir, $safeNames);
        $zip->close();
        if (!$extracted) {
            throw new UpdateException('core.update.error.archive');
        }
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private static function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            return false;
        }

        return !in_array('..', explode('/', $name), true);
    }
}
