<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Http;

use GuidelyTech\Flags\Exception\TransportException;

/**
 * Minimal HTTP transport. The default is {@see DefaultHttpClient} (cURL, or PHP streams when
 * ext-curl is missing); inject your own to route requests through Guzzle, a PSR-18 client, or a
 * fake in tests.
 */
interface HttpClientInterface
{
    /**
     * Send a request and return the response whatever its status code.
     *
     * @param array<string, string> $headers
     * @throws TransportException when no response could be obtained (DNS, connection, timeout)
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse;
}
