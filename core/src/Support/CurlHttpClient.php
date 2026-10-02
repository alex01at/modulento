<?php

declare(strict_types=1);

namespace Modulento\Core\Support;

/** HttpClient over curl: HTTPS only, certificates checked, short time limits. */
final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeout = 20, private int $connectTimeout = 8)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $lines = ['User-Agent: Modulento'];
        foreach ($headers as $name => $value) {
            // A line break would let a value add headers of its own.
            $lines[] = $name . ': ' . preg_replace('/[\r\n]+/', ' ', $value);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // These requests carry API keys: they go to the host that was
            // asked and nowhere else.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR => 'https',
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($answer) ? ['status' => $status, 'body' => $answer] : ['status' => 0, 'body' => ''];
    }
}
