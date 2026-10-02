<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/**
 * The one way the core talks to a payment service. An interface, so that
 * the checks replace it with something that records requests and answers
 * from a script - no test ever reaches the network.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers name => value
     * @param string|null $body the request body as it is sent, null for none
     * @return array{status: int, body: string} status 0 if no answer arrived
     *         (no connection, timeout, TLS failure)
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
