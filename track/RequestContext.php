<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * The request being served, as `captureException()` context keys (`request`, `transaction`).
 * Cookies, `Authorization` and other credential headers, and query values whose names look
 * sensitive, are removed when the event is built.
 *
 * ```php
 * // PSR-15 middleware (the SDK does not depend on psr/http-server-middleware; write it in your app):
 * public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
 * {
 *     try {
 *         return $handler->handle($request);
 *     } catch (\Throwable $e) {
 *         $this->guidely->captureException($e, RequestContext::fromPsr7($request) + ['handled' => false, 'mechanism' => 'request']);
 *         throw $e;
 *     }
 * }
 * ```
 */
final class RequestContext
{
    /**
     * From `$_SERVER` (an empty array on the CLI).
     *
     * @param array<string, mixed>|null $server default `$_SERVER`
     * @return array{request?: array{method: string, url: string, headers: array<string, string>}, transaction?: string}
     */
    public static function fromGlobals(?array $server = null, string $sapi = PHP_SAPI): array
    {
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return [];
        }
        $s = $server ?? $_SERVER;
        if (!isset($s['REQUEST_METHOD']) || !is_string($s['REQUEST_METHOD'])) {
            return [];
        }
        $https = isset($s['HTTPS']) && is_scalar($s['HTTPS']) && $s['HTTPS'] !== '' && strtolower((string) $s['HTTPS']) !== 'off';
        $host = is_string($s['HTTP_HOST'] ?? null) ? $s['HTTP_HOST'] : (is_string($s['SERVER_NAME'] ?? null) ? $s['SERVER_NAME'] : 'localhost');
        $uri = is_string($s['REQUEST_URI'] ?? null) ? $s['REQUEST_URI'] : '/';
        $headers = [];
        foreach ($s as $k => $v) {
            if (!is_string($k) || !is_scalar($v)) {
                continue;
            }
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = (string) $v;
            } elseif ($k === 'CONTENT_TYPE') {
                $headers['Content-Type'] = (string) $v;
            } elseif ($k === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = (string) $v;
            }
        }
        return self::of($s['REQUEST_METHOD'], ($https ? 'https' : 'http') . '://' . $host . $uri, $headers);
    }

    /**
     * From a PSR-7 request (duck-typed: anything with `getMethod()`, `getUri()` and `getHeaders()`).
     *
     * @return array{request?: array{method: string, url: string, headers: array<string, string|list<string>>}, transaction?: string}
     */
    public static function fromPsr7(object $request): array
    {
        if (!method_exists($request, 'getMethod') || !method_exists($request, 'getUri')) {
            return [];
        }
        $headers = method_exists($request, 'getHeaders') ? $request->getHeaders() : [];
        return self::of((string) $request->getMethod(), (string) $request->getUri(), is_array($headers) ? $headers : []);
    }

    /**
     * From its parts (any framework).
     *
     * @param array<string, string|list<string>> $headers
     * @return array{request: array{method: string, url: string, headers: array<string, string|list<string>>}, transaction: string}
     */
    public static function of(string $method, string $url, array $headers = []): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        return [
            'request' => ['method' => $method, 'url' => $url, 'headers' => $headers],
            'transaction' => strtoupper($method) . ' ' . (is_string($path) && $path !== '' ? $path : '/'),
        ];
    }
}
