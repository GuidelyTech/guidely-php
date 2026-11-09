<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

use GuidelyTech\Flags\GuidelyFlags;

/**
 * Tracing for one {@see GuidelyTrack} client. PHP runs one request at a time per process, so the
 * active span is the top of a stack: `startSpan()` pushes and pops, the request and job transactions
 * stay active until they end. A remote parent (`continueTrace()`, the incoming request's headers)
 * is the parent of the next transaction. Transactions become `transaction` messages
 * (docs/error-monitoring/performance.md) on the client's queue.
 *
 * @internal
 */
final class Tracer
{
    private const SENTRY_TRACE = '/^[ \t]*([0-9a-f]{32})-([0-9a-f]{16})(?:-([01]))?[ \t]*$/i';
    private const TRACEPARENT = '/^[ \t]*([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(?:-.*)?[ \t]*$/i';

    /** @var list<Span> */
    private array $stack = [];
    private ?Span $remote = null;
    private ?Span $request = null;
    private readonly ?string $ownHost;

    /**
     * @param \Closure(array): void $push queues a `transaction` message's fields
     * @param \Closure(array): void $breadcrumb records a breadcrumb in the error scope
     * @param \Closure(): float $random
     */
    public function __construct(
        private readonly TracingOptions $options,
        private readonly ErrorCapture $errors,
        string $apiUrl,
        private readonly \Closure $push,
        private readonly \Closure $breadcrumb,
        private readonly \Closure $random,
    ) {
        $host = parse_url($apiUrl, PHP_URL_HOST);
        $this->ownHost = is_string($host) ? strtolower($host) : null;
    }

    public function enabled(): bool
    {
        return $this->options->enabled();
    }

    // --- spans ----------------------------------------------------------------------------------

    /** The span of the running code: the innermost `startSpan()`, else the request / job transaction. */
    public function active(): ?Span
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    /** The active span, else the remote parent (what carries the trace onward). */
    public function carrier(): ?Span
    {
        return $this->active() ?? $this->remote;
    }

    /** @param array{name?: string, op?: string, attributes?: array<string, mixed>, startTime?: float, parentSpan?: Span|null, forceTransaction?: bool, onlyIfParent?: bool} $o */
    public function create(array $o, string $origin = 'manual'): Span
    {
        $name = is_string($o['name'] ?? null) && $o['name'] !== '' ? $o['name'] : '<unlabeled span>';
        $op = is_string($o['op'] ?? null) && $o['op'] !== '' ? ErrorCapture::cut($o['op'], 100) : null;
        $attributes = is_array($o['attributes'] ?? null) ? $o['attributes'] : [];
        $start = is_float($o['startTime'] ?? null) || is_int($o['startTime'] ?? null) ? (float) $o['startTime'] : microtime(true);
        $parent = array_key_exists('parentSpan', $o) ? ($o['parentSpan'] instanceof Span ? $o['parentSpan'] : null) : $this->carrier();
        $onEnd = fn (Span $s) => $this->ended($s);
        if ($parent !== null && !$parent->remote && ($o['forceTransaction'] ?? false) !== true) {
            $segment = $parent->sampled === true && $parent->segment !== null && !$parent->segment->finished ? $parent->segment : null;
            return (new Span($parent->traceId, self::id(8), $parent->spanId, $segment !== null ? true : ($parent->sampled ?? false), $name, $op, $origin, $start, $segment, false, $parent->dsc, false, $onEnd))->setAttributes($attributes);
        }
        if (($o['onlyIfParent'] ?? false) === true && $parent === null) {
            return new Span(self::id(16), self::id(8), null, false, $name, $op, $origin, $start, null, true, $this->dsc(self::id(16), false, null));
        }
        [$sampled, $rate] = $this->sample(['name' => $name, 'op' => $op, 'attributes' => $attributes, 'parentSampled' => $parent?->sampled]);
        $traceId = $parent?->traceId ?? self::id(16);
        $dsc = $parent?->remote ? $parent->dsc : ($parent?->dsc ?? $this->dsc($traceId, $sampled, $rate));
        $segment = $sampled ? new Segment($this->options->maxSpans, $rate) : null;
        return (new Span($traceId, self::id(8), $parent?->spanId, $sampled, $name, $op, $origin, $start, $segment, true, $dsc, false, $onEnd))->setAttributes($attributes);
    }

    /**
     * Runs `$fn` with `$span` active, ending it afterwards (marked `internal_error` when `$fn` throws).
     *
     * @template T
     * @param callable(Span): T $fn
     * @return T
     */
    public function run(Span $span, callable $fn, bool $end = true): mixed
    {
        $this->stack[] = $span;
        try {
            return $fn($span);
        } catch (\Throwable $e) {
            $span->setStatus('internal_error');
            throw $e;
        } finally {
            $this->remove($span);
            if ($end) {
                $span->end();
            }
        }
    }

    /** Makes `$span` the active span until it ends (request and job transactions). */
    public function activate(Span $span): void
    {
        $this->stack[] = $span;
    }

    private function remove(Span $span): void
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i] === $span) {
                array_splice($this->stack, $i, 1);
                return;
            }
        }
    }

    /** @return array{0: bool, 1: ?float} */
    private function sample(array $ctx): array
    {
        if (!$this->enabled()) {
            return [false, null];
        }
        $rate = null;
        if ($this->options->tracesSampler !== null) {
            try {
                $r = ($this->options->tracesSampler)($ctx);
                $rate = is_bool($r) ? ($r ? 1.0 : 0.0) : (is_int($r) || is_float($r) ? max(0.0, min(1.0, (float) $r)) : null);
            } catch (\Throwable) {
                $rate = null;
            }
        }
        if ($rate === null && $ctx['parentSampled'] !== null) {
            return [$ctx['parentSampled'], null];
        }
        $rate ??= $this->options->tracesSampleRate ?? 0.0;
        return [$rate > 0 && ($this->random)() < $rate, $rate];
    }

    private function ended(Span $span): void
    {
        $this->remove($span);
        if ($span === $this->request) {
            $this->request = null;
        }
        $segment = $span->segment;
        if ($span->sampled !== true || $segment === null || $span->remote) {
            return;
        }
        if (!$span->isRoot) {
            $segment->add($span->toArray());
            return;
        }
        if ($segment->finished) {
            return;
        }
        $segment->finished = true;
        $this->send($span);
    }

    // --- trace headers --------------------------------------------------------------------------

    /**
     * Continues an upstream trace: the next transaction joins it. Headers by name (any case):
     * `sentry-trace`, `baggage`, `traceparent`. Returns whether they named a trace.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function continueTrace(array $headers): bool
    {
        $this->remote = $this->remoteOf($headers);
        return $this->remote !== null;
    }

    /** @param array<string, string|list<string>> $headers */
    private function remoteOf(array $headers): ?Span
    {
        $h = [];
        foreach ($headers as $k => $v) {
            if (is_string($k)) {
                $h[strtolower(str_replace('_', '-', $k))] = is_array($v) ? (is_string($v[0] ?? null) ? $v[0] : '') : (is_string($v) ? $v : '');
            }
        }
        $traceId = $parentId = null;
        $sampled = null;
        if (preg_match(self::SENTRY_TRACE, $h['sentry-trace'] ?? '', $m) === 1 && trim($m[1], '0') !== '' && trim($m[2], '0') !== '') {
            [$traceId, $parentId] = [strtolower($m[1]), strtolower($m[2])];
            $sampled = isset($m[3]) && $m[3] !== '' ? $m[3] === '1' : null;
        } elseif (preg_match(self::TRACEPARENT, $h['traceparent'] ?? '', $m) === 1 && strtolower($m[1]) !== 'ff' && trim($m[2], '0') !== '' && trim($m[3], '0') !== '') {
            [$traceId, $parentId] = [strtolower($m[2]), strtolower($m[3])];
            $sampled = (hexdec($m[4]) & 1) === 1;
        }
        if ($traceId === null || $parentId === null) {
            return null;
        }
        $dsc = self::parseBaggage($h['baggage'] ?? '') ?? $this->dsc($traceId, null, null);
        return new Span($traceId, $parentId, null, $sampled, 'remote', null, 'remote', microtime(true), null, false, $dsc, true);
    }

    /** @return array<string, string>|null the `sentry-*` members of a `baggage` header */
    public static function parseBaggage(string $header): ?array
    {
        $out = [];
        foreach (array_slice(explode(',', $header), 0, 64) as $member) {
            $pair = explode(';', $member, 2)[0];
            $i = strpos($pair, '=');
            if ($i === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $i));
            if (str_starts_with($key, 'sentry-')) {
                $out[substr($key, 7)] = ErrorCapture::cut(rawurldecode(trim(substr($pair, $i + 1))), 200);
            }
        }
        return $out === [] ? null : $out;
    }

    /** @return array{sentry-trace?: string, baggage?: string, traceparent?: string} headers that carry the active trace (empty without one) */
    public function headers(?Span $span = null): array
    {
        $s = $span ?? $this->carrier();
        if ($s === null) {
            return [];
        }
        $out = [
            'sentry-trace' => $s->traceId . '-' . $s->spanId . ($s->sampled === null ? '' : ($s->sampled ? '-1' : '-0')),
            'traceparent' => '00-' . $s->traceId . '-' . $s->spanId . '-' . ($s->sampled ? '01' : '00'),
        ];
        $baggage = implode(',', array_map(static fn ($k, $v) => 'sentry-' . $k . '=' . rawurlencode((string) $v), array_keys($s->dsc), $s->dsc));
        if ($baggage !== '') {
            $out['baggage'] = $baggage;
        }
        return $out;
    }

    /** @return array<string, string> */
    private function dsc(string $traceId, ?bool $sampled, ?float $rate): array
    {
        $d = ['trace_id' => $traceId];
        if ($this->errors->environment() !== null) {
            $d['environment'] = $this->errors->environment();
        }
        if ($this->errors->release() !== null) {
            $d['release'] = $this->errors->release();
        }
        if ($rate !== null) {
            $d['sample_rate'] = (string) $rate;
        }
        if ($sampled !== null) {
            $d['sampled'] = $sampled ? 'true' : 'false';
        }
        return $d;
    }

    /** Whether an outgoing request gets trace headers (`tracePropagationTargets`; never Guidely's own API). */
    public function propagates(string $url): bool
    {
        if ($this->isOwn($url)) {
            return false;
        }
        $targets = $this->options->tracePropagationTargets;
        return $targets === null || TracingOptions::matches($targets, $url);
    }

    public function isOwn(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $this->ownHost !== null && is_string($host) && strtolower($host) === $this->ownHost;
    }

    // --- requests -------------------------------------------------------------------------------

    /**
     * An `http.server` transaction for the request being served, continuing the caller's trace,
     * active until it ends (by default at the end of the request, with `http_response_code()`).
     *
     * @param array{method?: string, url?: string, headers?: array<string, string|list<string>>} $request
     */
    public function startRequest(array $request): ?Span
    {
        if (!$this->enabled()) {
            return null;
        }
        $this->request?->end();
        $method = strtoupper(is_string($request['method'] ?? null) && $request['method'] !== '' ? $request['method'] : 'GET');
        $url = is_string($request['url'] ?? null) ? $request['url'] : '/';
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $remote = $this->remoteOf(is_array($request['headers'] ?? null) ? $request['headers'] : []);
        if ($remote !== null) {
            $this->remote = $remote;
        }
        $span = $this->create(['name' => "$method " . self::pathName($path), 'op' => 'http.server', 'forceTransaction' => true, 'parentSpan' => $this->remote, 'attributes' => ['http.request.method' => $method, 'url.path' => ErrorCapture::cut($path, 2000)]], 'auto.http.php');
        if ($span->segment !== null) {
            $span->segment->source = 'url';
            $span->segment->request = ErrorCapture::request(['method' => $method, 'url' => $url]);
        }
        $this->request = $span;
        $this->activate($span);
        return $span;
    }

    public function requestSpan(): ?Span
    {
        return $this->request;
    }

    /** Names the request transaction by its route (`/users/{id}`): `GET /users/{id}`, source `route`. */
    public function setRoute(string $route): void
    {
        $span = $this->request;
        if ($span === null || $route === '') {
            return;
        }
        $route = '/' . ltrim($route, '/');
        $method = $span->attributes()['http.request.method'] ?? 'GET';
        $span->updateName($method . ' ' . $route);
        $span->setAttribute('http.route', $route);
        if ($span->segment !== null) {
            $span->segment->source = 'route';
        }
    }

    /** Ends the request transaction with the response status (`http_response_code()` when null). */
    public function finishRequest(?int $status = null): void
    {
        $span = $this->request;
        if ($span === null) {
            return;
        }
        $code = $status ?? (PHP_SAPI === 'cli' ? false : http_response_code());
        if (is_int($code)) {
            $span->setHttpStatus($code);
        }
        $span->end();
    }

    /** `/users/42/orders/9f1c…` → `/users/*\/orders/*`: a request's name until its route is known. */
    public static function pathName(string $path): string
    {
        $segments = array_map(static fn (string $s) => preg_match('/^(?:\d+|[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}|[0-9a-f]{12,})$/i', $s) === 1 || (strlen($s) >= 20 && preg_match('/\d/', $s) === 1 && preg_match('/^[\w-]+$/', $s) === 1) ? '*' : $s, explode('/', $path));
        return ErrorCapture::cut(implode('/', $segments), 2000);
    }

    // --- queries and outgoing calls --------------------------------------------------------------

    /**
     * A query that ran: a `db` span under the active span (when it records) and a `query` breadcrumb.
     *
     * @param array{system?: ?string, database?: ?string, host?: ?string, port?: ?int, rows?: ?int, error?: \Throwable|string|null, origin?: string} $o
     */
    public function query(string $sql, float $durationMs, array $o = []): void
    {
        $system = is_string($o['system'] ?? null) && $o['system'] !== '' ? $o['system'] : null;
        $text = Sql::scrub($sql, $system);
        $error = $o['error'] ?? null;
        $message = $error instanceof \Throwable ? $error->getMessage() : (is_string($error) && $error !== '' ? $error : null);
        $rows = is_int($o['rows'] ?? null) ? $o['rows'] : null;
        $database = is_string($o['database'] ?? null) && $o['database'] !== '' ? $o['database'] : null;
        $end = microtime(true);
        $parent = $this->active();
        if ($parent !== null && $parent->isRecording()) {
            $attributes = array_filter([
                'db.system' => $system,
                'db.name' => $database,
                'db.operation' => Sql::operation($text),
                'server.address' => is_string($o['host'] ?? null) && $o['host'] !== '' ? $o['host'] : null,
                'server.port' => is_int($o['port'] ?? null) ? $o['port'] : null,
                'db.response.returned_rows' => $rows,
            ], static fn ($v) => $v !== null);
            $span = $this->create(['name' => $text, 'op' => 'db', 'parentSpan' => $parent, 'startTime' => $end - max(0.0, $durationMs) / 1000, 'attributes' => $attributes], is_string($o['origin'] ?? null) ? $o['origin'] : 'manual');
            if ($message !== null) {
                $span->setStatus('internal_error');
            }
            $span->end($end);
        }
        $data = array_filter(['db.system' => $system, 'db.name' => $database, 'duration_ms' => round($durationMs, 2), 'rows' => $rows, 'error' => $message === null ? null : ErrorCapture::cut($message, 200)], static fn ($v) => $v !== null);
        ($this->breadcrumb)(['type' => 'query', 'category' => 'query', 'message' => ErrorCapture::cut($text, 1000), 'level' => $message === null ? 'info' : 'error', 'data' => $data]);
    }

    /**
     * A Guzzle middleware (`$stack->push(…)`): an `http.client` span per request and trace headers.
     * Guzzle is not a dependency; requests and promises are duck-typed (PSR-7, guzzlehttp/promises).
     */
    public function guzzleMiddleware(): \Closure
    {
        return fn (callable $handler): \Closure => function ($request, array $options) use ($handler) {
            if (!is_object($request) || !method_exists($request, 'getUri') || !method_exists($request, 'getMethod')) {
                return $handler($request, $options);
            }
            $url = (string) $request->getUri();
            if ($this->isOwn($url)) {
                return $handler($request, $options);
            }
            $method = strtoupper((string) $request->getMethod());
            $clean = (string) preg_replace('/[?#].*$/s', '', $url);
            $parent = $this->active();
            $span = null;
            if ($parent !== null && $parent->isRecording()) {
                $host = parse_url($url, PHP_URL_HOST);
                $port = parse_url($url, PHP_URL_PORT);
                $span = $this->create(['name' => "$method $clean", 'op' => 'http.client', 'parentSpan' => $parent, 'attributes' => array_filter(['http.request.method' => $method, 'url.full' => $clean, 'server.address' => is_string($host) ? $host : null, 'server.port' => is_int($port) ? $port : null], static fn ($v) => $v !== null)], 'auto.http.guzzle');
            }
            $carrier = $span ?? $this->carrier();
            if ($carrier !== null && $this->propagates($url) && method_exists($request, 'withHeader') && method_exists($request, 'hasHeader') && !$request->hasHeader('sentry-trace') && !$request->hasHeader('traceparent')) {
                $h = $this->headers($carrier);
                $request = $request->withHeader('sentry-trace', $h['sentry-trace'])->withHeader('traceparent', $h['traceparent']);
                if (isset($h['baggage'])) {
                    $existing = method_exists($request, 'getHeaderLine') ? (string) $request->getHeaderLine('baggage') : '';
                    if (!str_contains($existing, 'sentry-')) {
                        $request = $request->withHeader('baggage', $existing === '' ? $h['baggage'] : $existing . ',' . $h['baggage']);
                    }
                }
            }
            $promise = $handler($request, $options);
            if ($span === null || !is_object($promise) || !method_exists($promise, 'then')) {
                $span?->end();
                return $promise;
            }
            return $promise->then(
                static function ($response) use ($span) {
                    if (is_object($response) && method_exists($response, 'getStatusCode')) {
                        $span->setHttpStatus((int) $response->getStatusCode());
                    }
                    $span->end();
                    return $response;
                },
                static function ($reason) use ($span) {
                    $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                    if (is_object($response) && method_exists($response, 'getStatusCode')) {
                        $span->setHttpStatus((int) $response->getStatusCode());
                    } else {
                        $span->setStatus('internal_error');
                    }
                    $span->end();
                    if ($reason instanceof \Throwable) {
                        throw $reason;
                    }
                    return class_exists('GuzzleHttp\Promise\Create') ? \GuzzleHttp\Promise\Create::rejectionFor($reason) : throw new \RuntimeException(is_string($reason) ? $reason : 'Request failed');
                },
            );
        };
    }

    /** Trace context for an error captured now, linking it to the active trace. */
    public function errorContext(): ?array
    {
        $s = $this->carrier() ?? $this->request;
        if ($s === null) {
            return null;
        }
        return array_filter(['trace_id' => $s->traceId, 'span_id' => $s->spanId, 'parent_span_id' => $s->parentSpanId], static fn ($v) => $v !== null);
    }

    /** Ends what is still open at the end of the request: the request transaction, then any other transaction. */
    public function finishAll(): void
    {
        $this->finishRequest();
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $s = $this->stack[$i] ?? null;
            if ($s !== null && $s->isRoot) {
                $s->end();
            }
        }
        $this->stack = [];
    }

    // --- sending --------------------------------------------------------------------------------

    private function send(Span $root): void
    {
        try {
            $segment = $root->segment;
            if ($segment === null || TracingOptions::matches($this->options->ignoreTransactions, $root->name())) {
                return;
            }
            $end = $root->endTime() ?? microtime(true);
            $traceData = $root->attributes();
            if ($segment->sampleRate !== null) {
                $traceData['sentry.sample_rate'] = $segment->sampleRate;
            }
            if ($segment->dropped > 0) {
                $traceData['guidely.dropped_spans'] = $segment->dropped;
            }
            $trace = ['trace_id' => $root->traceId, 'span_id' => $root->spanId, 'op' => $root->op() ?? 'default', 'status' => $root->status() ?? 'ok', 'origin' => $root->origin];
            if ($root->parentSpanId !== null) {
                $trace['parent_span_id'] = $root->parentSpanId;
            }
            if ($traceData !== []) {
                $trace['data'] = $traceData;
            }
            $contexts = ['runtime' => ErrorCapture::runtimeContext(), 'os' => ErrorCapture::osContext()] + ErrorCapture::scrub($this->errors->scopeContexts());
            $contexts['trace'] = $trace;
            if (is_int($traceData['http.response.status_code'] ?? null)) {
                $contexts['response'] = ['status_code' => $traceData['http.response.status_code']];
            }
            $id = bin2hex(random_bytes(16));
            $event = [
                'event_id' => $id,
                'type' => 'transaction',
                'transaction' => $root->name(),
                'transaction_info' => ['source' => $segment->source],
                'start_timestamp' => round($root->startTime, 6),
                'timestamp' => round($end, 6),
                'platform' => ErrorCapture::PLATFORM,
                'contexts' => $contexts,
                'spans' => $segment->spans,
                'sdk' => ['name' => GuidelyFlags::SDK_NAME, 'version' => GuidelyFlags::VERSION],
            ];
            foreach (['release' => $this->errors->release(), 'environment' => $this->errors->environment(), 'server_name' => $this->errors->serverName()] as $k => $v) {
                if ($v !== null) {
                    $event[$k] = $v;
                }
            }
            $tags = $this->errors->scopeTags();
            if ($tags !== []) {
                $event['tags'] = $tags;
            }
            $user = $this->errors->scopeUser();
            if ($user !== null) {
                $event['user'] = $user;
            }
            if ($segment->request !== null && $segment->request !== []) {
                $event['request'] = $segment->request;
            }
            ($this->push)(['messageId' => $id, 'timestamp' => $end, 'userId' => $user['id'] ?? null, 'transaction' => $event]);
        } catch (\Throwable) {
            // a transaction must never break the app
        }
    }

    /** `$bytes` random bytes as hex: 16 for a trace id, 8 for a span id. */
    private static function id(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
