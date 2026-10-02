<?php

declare(strict_types=1);

namespace Modulento\Core\Payment;

use RuntimeException;

/**
 * A payment service did not do what was asked. Carries the language key of
 * what the person is told; what the service answered stays out of it, since
 * such answers can name accounts or echo parts of a key.
 */
final class PaymentException extends RuntimeException
{
    /**
     * @param string $messageKey language key shown to the person
     * @param string $detail for the log: the request's path, the HTTP status and the service's error code - never a secret
     */
    public function __construct(public readonly string $messageKey, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $messageKey);
    }
}
