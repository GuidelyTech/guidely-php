<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Exception\TransportException;
use GuidelyTech\Flags\Http\HttpClientInterface;
use GuidelyTech\Flags\Http\HttpResponse;

/** Records requests and answers them with a handler (or a queue of responses). */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, path: string, query: array<string, string>, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var \Closure(array): (HttpResponse|\Throwable) */
    private \Closure $handler;

    /** @param callable(array): (HttpResponse|\Throwable) $handler */
    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    /** Answers GET /flags with the payload (ETag "v<version>") and 304 when it matches. */
    public static function serving(array &$payload): self
    {
        return new self(static function (array $request) use (&$payload): HttpResponse {
            if ($request['path'] === '/api/v1/sdk/flags') {
                $etag = '"v' . $payload['version'] . '"';
                if (($request['headers']['If-None-Match'] ?? null) === $etag) {
                    return new HttpResponse(304, '', ['etag' => $etag]);
                }
                return new HttpResponse(200, json_encode($payload), ['etag' => $etag, 'content-type' => 'application/json']);
            }
            return new HttpResponse(202, '{}');
        });
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): HttpResponse
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $request = ['method' => $method, 'url' => $url, 'path' => $parts['path'] ?? '', 'query' => $query, 'headers' => $headers, 'body' => $body];
        $this->requests[] = $request;
        $result = ($this->handler)($request);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        return $result;
    }

    /** @return list<array> requests to a path */
    public function to(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $r) => $r['path'] === $path));
    }

    public static function down(): self
    {
        return new self(static fn () => new TransportException('Connection refused'));
    }
}
