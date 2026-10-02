<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use RuntimeException;

/**
 * Encrypts what must not be readable in a database dump: API keys of
 * payment services. The key lives in a file outside the web root and never
 * in the database, so a leaked dump alone opens nothing. The file belongs
 * to every backup - without it the stored secrets are lost.
 */
final class Secrets
{
    private const PREFIX = 'v1:';

    private ?string $key = null;

    public function __construct(private string $keyPath)
    {
    }

    /** False on a PHP without libsodium; nothing can be encrypted then. */
    public static function available(): bool
    {
        return extension_loaded('sodium');
    }

    /** @throws RuntimeException if the key file cannot be created or read */
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key(create: true)));
    }

    /** Null if the value was not encrypted with this installation's key, or was changed. */
    public function decrypt(string $stored): ?string
    {
        if (!self::available() || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        try {
            $key = $this->key(create: false);
        } catch (RuntimeException) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );

        return $plain === false ? null : $plain;
    }

    private function key(bool $create): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!self::available()) {
            throw new RuntimeException('The PHP extension sodium is missing');
        }

        if (!is_file($this->keyPath)) {
            if (!$create) {
                throw new RuntimeException('There is no key file yet');
            }
            $this->createKeyFile();
        }

        // Another request may be writing the file this very moment.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = @file_get_contents($this->keyPath);
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $this->key = $key;
            }
            usleep(50000);
            clearstatcache(true, $this->keyPath);
        }

        throw new RuntimeException('The key file ' . basename($this->keyPath) . ' cannot be read or is damaged');
    }

    /**
     * Created on first need, so that nothing has to be prepared by hand on
     * the server. Mode "x" fails if the file exists: of two requests at
     * once only one writes a key, the other reads it.
     */
    private function createKeyFile(): void
    {
        $dir = dirname($this->keyPath);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('The folder for the key file cannot be created');
        }

        $before = umask(0077);
        $handle = @fopen($this->keyPath, 'x');
        umask($before);

        if ($handle === false) {
            if (is_file($this->keyPath)) {
                return;
            }
            throw new RuntimeException('The key file ' . basename($this->keyPath) . ' cannot be written');
        }

        fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        fclose($handle);
        @chmod($this->keyPath, 0600);
    }
}
