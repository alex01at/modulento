<?php

declare(strict_types=1);

namespace Modulento\Core\Extension;

use InvalidArgumentException;

/** The parsed and validated extension.json of one extension folder. */
final class Manifest
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly int $api,
        public readonly string $namespace,
        public readonly string $entry,
        public readonly string $dir,
    ) {
    }

    public static function fromDir(string $dir): self
    {
        $file = $dir . '/extension.json';
        if (!is_file($file)) {
            throw new InvalidArgumentException('extension.json is missing');
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('extension.json is not valid JSON');
        }

        foreach (['id', 'name', 'version', 'namespace'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("extension.json: \"{$field}\" is missing");
            }
        }

        // The id doubles as table prefix, Twig namespace and language-key
        // prefix, and has to equal the folder name so it is unique.
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $data['id']) || $data['id'] === 'core') {
            throw new InvalidArgumentException('extension.json: "id" must be 2-32 characters of a-z, 0-9 and _, and not "core"');
        }
        if ($data['id'] !== basename($dir)) {
            throw new InvalidArgumentException('extension.json: "id" must equal the folder name');
        }
        if (!isset($data['api']) || !is_int($data['api'])) {
            throw new InvalidArgumentException('extension.json: "api" must be an integer');
        }

        $namespace = rtrim($data['namespace'], '\\') . '\\';

        return new self(
            $data['id'],
            $data['name'],
            $data['version'],
            $data['api'],
            $namespace,
            $namespace . 'Extension',
            $dir,
        );
    }
}
