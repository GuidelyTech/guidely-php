<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Http;

/** A raw HTTP response. Header names are lowercase. */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers lowercase header name => value
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
