<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

use RuntimeException;

/**
 * Carries a language key instead of a finished sentence, so the update
 * page can show the reason in the administrator's language.
 */
final class UpdateException extends RuntimeException
{
    /** @param array<string, string|int> $params placeholders for the language text */
    public function __construct(public readonly string $messageKey, public readonly array $params = [])
    {
        parent::__construct($messageKey . ($params !== [] ? ' ' . json_encode($params) : ''));
    }
}
