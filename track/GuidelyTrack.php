<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Http\DefaultHttpClient;
use GuidelyTech\Flags\Http\HttpClientInterface;

/**
 * Server-side Track Events (docs/server-sdks.md): who a user is (`identify`), what they did
 * (`track`) and which account they belong to (`group`), sent from your backend.
 *
 * ```php
 * $guidely = new GuidelyTrack(getenv('GUIDELY_SECRET_KEY'), environment: 'production');
 * $guidely->track(['userId' => 'u_42', 'event' => 'Report Exported', 'properties' => ['format' => 'csv']]);
 * ```
 *
 * A PHP process does not outlive the request, so there is no background thread: messages are queued
 * in memory and sent synchronously — when `flushAt` are queued, on `flush()`, and at the end of the
 * request (destructor / shutdown function). Retries happen inline with short sleeps (by default at
 * most 3 retries of 0.1 s, 0.2 s, 0.4 s; `Retry-After` is honoured up to 1 s), so an outage adds a
 * bounded delay to the request instead of losing data or blocking for minutes.
 *
 * It also reports errors (`captureException`, `captureMessage`) the way Sentry's SDK does — scope
 * (`setTag`, `setUser`, `addBreadcrumb`…), {@see ErrorOptions} (`beforeSend`, `sampleRate`, ignore
 * lists, in-app paths) and an opt-in {@see ErrorHandler} for uncaught exceptions, PHP errors and
 * fatal errors — as `exception` messages on the same batch path. They are sent with the end-of-request
 * flush (crashes and fatal errors at once).
 *
 * With {@see TracingOptions} it traces too (docs/error-monitoring/performance.md): `startSpan()`,
 * request and job transactions (`traceRequest()`, `startTransaction()`), `db` spans for PDO and
 * framework query events, `http.client` spans and trace headers through a Guzzle middleware, and
 * {@see Laravel::instrument()} to wire all of it into a Laravel app. Transactions are `transaction`
 * messages, sent with the end-of-request flush.
 *
 * Calls never throw: invalid calls are dropped and reported to `onError` / the logger.
 */
final class GuidelyTrack
{
    public const SDK_NAME = GuidelyFlags::SDK_NAME;
    public const VERSION = GuidelyFlags::VERSION;
    public const DEFAULT_API_URL = 'https://guidelytech.com';
    /** The built-in environments; projects can add their own (`qa`, `uat`…), matching ENVIRONMENT_KEY. */
    public const ENVIRONMENTS = ['development', 'staging', 'production'];
    public const ENVIRONMENT_KEY = '/^[a-z][a-z0-9-]{0,29}$/';
    private const PATH_BATCH = '/api/v1/sdk/server/batch';

    private readonly string $url;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly HttpClientInterface $http;
    private readonly int $batchCap;
    /** @var (\Closure(\Throwable): void)|null */
    private readonly ?\Closure $onError;
    /** @var \Closure(int): void sleeps this many milliseconds */
    private readonly \Closure $sleep;
    /** @var \Closure(): float in [0, 1) */
    private readonly \Closure $random;

    /** @var list<array{0: string, 1: int}> serialized messages and their sizes */
    private array $queue = [];
    private int $droppedSinceReport = 0;
    private bool $sending = false;
    private bool $closed = false;
    private readonly ?string $appVersion;
    private readonly ErrorCapture $errors;
    private readonly Tracer $tracer;

    /**
     * @param string $secretKey a secret API key (`gly_sk_…`) with the `events:write` scope
     * @param string $environment the environment key: `development`, `staging`, `production` or one you added (`qa`)
     * @param int $flushAt send when this many messages are queued (a batch holds at most 100 messages and 500 KB)
     * @param int $maxQueueSize most messages held; beyond it the oldest are dropped and reported
     * @param float $timeoutSeconds timeout of one request (connect and total)
     * @param int $maxRetries retries after network errors, timeouts, 429 and 5xx (0 = no retries)
     * @param int $retryBaseDelayMs first backoff; it doubles with every retry
     * @param int $retryMaxDelayMs longest backoff, and the cap on `Retry-After`
     * @param callable(\Throwable): void|null $onError receives every {@see TrackException}
     * @param object|null $logger PSR-3 style logger (anything with `error(string, array)`; `warning()` is used when present)
     * @param HttpClientInterface|null $httpClient transport (default: cURL, or PHP streams)
     * @param bool $flushOnShutdown flush at the end of the request (`register_shutdown_function`) and when the client is destroyed
     * @param callable(int): void|null $sleep @internal waits this many milliseconds (tests)
     * @param callable(): float|null $random @internal jitter source in [0, 1) (tests)
     * @param string|null $appVersion your app's version, sent with every `track` (a message's own `appVersion` wins)
     * @param ErrorOptions|null $errors error monitoring settings (`beforeSend`, `sampleRate`, `ignoreErrors`, `release`…)
     * @param TracingOptions|null $tracing performance tracing (`tracesSampleRate`…); off by default
     * @throws \InvalidArgumentException when the key or environment is wrong
     */
    public function __construct(
        string $secretKey,
        string $environment,
        string $apiUrl = self::DEFAULT_API_URL,
        private readonly int $flushAt = 100,
        private readonly int $maxQueueSize = 10000,
        private readonly float $timeoutSeconds = 3.0,
        private readonly int $maxRetries = 3,
        private readonly int $retryBaseDelayMs = 100,
        private readonly int $retryMaxDelayMs = 1000,
        ?callable $onError = null,
        private readonly ?object $logger = null,
        ?HttpClientInterface $httpClient = null,
        bool $flushOnShutdown = true,
        ?callable $sleep = null,
        ?callable $random = null,
        ?string $appVersion = null,
        ?ErrorOptions $errors = null,
        ?TracingOptions $tracing = null,
    ) {
        $this->appVersion = Messages::version($appVersion);
        $key = trim($secretKey);
        if (!str_starts_with($key, 'gly_sk_')) {
            throw new \InvalidArgumentException('GuidelyTrack needs a secret API key (gly_sk_…) with the events:write scope');
        }
        if (preg_match(self::ENVIRONMENT_KEY, $environment) !== 1) {
            throw new \InvalidArgumentException('"environment" must be an environment key: development, staging, production or one you added (e.g. qa), as shown in Settings → Environments');
        }
        if ($flushAt < 1 || $maxQueueSize < 1 || $maxRetries < 0 || $retryBaseDelayMs < 0 || $retryMaxDelayMs < 0) {
            throw new \InvalidArgumentException('flushAt and maxQueueSize must be >= 1; maxRetries and the retry delays >= 0');
        }
        if ($logger !== null && !method_exists($logger, 'error')) {
            throw new \InvalidArgumentException('"logger" must have an error(string $message, array $context) method (PSR-3)');
        }
        $this->url = rtrim($apiUrl, '/') . self::PATH_BATCH . '?environment=' . rawurlencode($environment);
        $this->headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::SDK_NAME . '/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        $this->http = $httpClient ?? new DefaultHttpClient();
        $this->batchCap = min($flushAt, Messages::MAX_BATCH_MESSAGES);
        $this->onError = $onError === null ? null : \Closure::fromCallable($onError);
        $this->sleep = $sleep === null ? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        } : \Closure::fromCallable($sleep);
        $this->random = $random === null ? static fn (): float => mt_rand() / (mt_getrandmax() + 1) : \Closure::fromCallable($random);
        $this->errors = new ErrorCapture($errors ?? new ErrorOptions(), $environment, $this->appVersion, $this->random);
        // Weak references: neither the tracer nor the shutdown function may keep the client alive past
        // unset() (its destructor flushes then).
        $self = \WeakReference::create($this);
        $this->tracer = new Tracer(
            $tracing ?? new TracingOptions(),
            $this->errors,
            $apiUrl,
            static function (array $args) use ($self): void {
                $self->get()?->enqueueTransaction($args);
            },
            static function (array $crumb) use ($self): void {
                $self->get()?->addBreadcrumb($crumb);
            },
            $this->random,
        );
        if ($flushOnShutdown) {
            register_shutdown_function(static function () use ($self): void {
                $client = $self->get();
                if ($client !== null) {
                    $client->tracer->finishAll();
                    $client->flush();
                }
            });
        }
    }

    public function __destruct()
    {
        $this->tracer->finishAll();
        if ($this->queue !== []) {
            $this->flush();
        }
    }

    /**
     * Who a user is: merges `traits` into the user (creating it if needed).
     *
     * @param array{userId: string|int, traits?: array<string, mixed>|null, accountId?: string|int|null, timestamp?: \DateTimeInterface|string|int|float|null} $message
     * @return bool whether the message was queued (false: invalid or closed, and reported)
     */
    public function identify(array $message): bool
    {
        return $this->enqueue('identify', $message);
    }

    /**
     * What a user (or an anonymous visitor: `anonymousId` instead of `userId`) did.
     *
     * @param array{event: string, userId?: string|int|null, anonymousId?: string|null, properties?: array<string, mixed>|null, accountId?: string|int|null, timestamp?: \DateTimeInterface|string|int|float|null, appVersion?: string|null} $message
     */
    public function track(array $message): bool
    {
        if ($this->appVersion !== null && ($message['appVersion'] ?? null) === null) {
            $message['appVersion'] = $this->appVersion;
        }
        return $this->enqueue('track', $message);
    }

    /**
     * Account traits (`name` also becomes the account name); `userId` adds the user to the account.
     *
     * @param array{accountId: string|int, traits?: array<string, mixed>|null, userId?: string|int|null, timestamp?: \DateTimeInterface|string|int|float|null} $message
     */
    public function group(array $message): bool
    {
        return $this->enqueue('group', $message);
    }

    /**
     * Sends everything queued, synchronously. Returns true when every batch was accepted. Never
     * throws; failures are reported.
     */
    public function flush(): bool
    {
        try {
            return $this->send(true);
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::DELIVERY_FAILED, 'flush() failed: ' . $e->getMessage(), 0, null, $e));
            return false;
        }
    }

    /** Ends open transactions, flushes, then drops (and reports) later calls. */
    public function close(): void
    {
        $this->tracer->finishAll();
        $this->flush();
        $this->closed = true;
    }

    /** Messages waiting to be sent. */
    public function queuedCount(): int
    {
        return count($this->queue);
    }

    // --- error monitoring -----------------------------------------------------------------------

    /**
     * Reports a caught exception (level `error`, `handled: true`) with its previous exceptions,
     * breadcrumbs and the client's scope. Never throws.
     *
     * @param array{userId?: string|int|null, anonymousId?: string|null, accountId?: string|int|null, tags?: array<string, string>, extra?: array<string, mixed>, level?: Level|string, fingerprint?: list<string>, contexts?: array<string, array<string, mixed>|null>, request?: array{method?: string, url?: string, headers?: array<string, string|list<string>>}, transaction?: string, handled?: bool, mechanism?: string} $context
     *     any other key is kept as an event property
     * @return string|null the event id (`messageId`), or null when it was dropped (sampled out,
     *     ignored, `beforeSend` returned null, invalid, or the client is closed)
     */
    public function captureException(\Throwable $exception, array $context = []): ?string
    {
        $how = [
            'source' => 'manual',
            'mechanism' => is_string($context['mechanism'] ?? null) && $context['mechanism'] !== '' ? $context['mechanism'] : 'generic',
            'handled' => is_bool($context['handled'] ?? null) ? $context['handled'] : true,
        ];
        return $this->capture($exception, null, null, $context, $how);
    }

    /**
     * Reports a message (level `info` by default); events group by the message.
     *
     * @param array<string, mixed> $context as for captureException()
     */
    public function captureMessage(string $message, Level|string $level = Level::Info, array $context = []): ?string
    {
        $context['level'] = Level::of($level) ?? Level::Info;
        return $this->capture(null, null, $message, $context, ['source' => 'message', 'mechanism' => null, 'handled' => true]);
    }

    /** A tag on every later error (null removes it). */
    public function setTag(string $key, ?string $value): void
    {
        $this->errors->setTag($key, $value);
    }

    /** @param array<string, string> $tags */
    public function setTags(array $tags): void
    {
        foreach ($tags as $k => $v) {
            $this->errors->setTag((string) $k, $v === null ? null : (string) $v);
        }
    }

    /** Extra data on every later error (null removes it). */
    public function setExtra(string $key, mixed $value): void
    {
        $this->errors->setExtra($key, $value);
    }

    /** @param array<string, mixed> $extras */
    public function setExtras(array $extras): void
    {
        foreach ($extras as $k => $v) {
            $this->errors->setExtra((string) $k, $v);
        }
    }

    /**
     * A named context on every later error (null removes it).
     *
     * @param array<string, mixed>|null $context
     */
    public function setContext(string $name, ?array $context): void
    {
        $this->errors->setContext($name, $context);
    }

    /**
     * The user later errors are attributed to (their `userId` and `detail.user`); null clears it. A
     * capture's own `userId` wins. Unlike the browser SDK this sends no `identify`: call
     * identify() for traits.
     *
     * @param array{id?: string|int|null, email?: string|null, username?: string|null}|null $user
     */
    public function setUser(?array $user): void
    {
        $this->errors->setUser($user);
    }

    /**
     * Records a breadcrumb; the last 100 ride along with every error.
     *
     * @param array{category?: string, message?: string, level?: Level|string, type?: string, data?: array<string, mixed>, timestamp?: \DateTimeInterface|float|int} $breadcrumb
     */
    public function addBreadcrumb(array $breadcrumb): void
    {
        try {
            $this->errors->addBreadcrumb($breadcrumb, new \DateTimeImmutable('now'));
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'addBreadcrumb() failed: ' . $e->getMessage(), 1, null, $e));
        }
    }

    // --- tracing --------------------------------------------------------------------------------

    /**
     * Runs `$fn` in a span — a child of the active span, or the root of a new transaction — and ends
     * it when `$fn` returns (marked `internal_error` when it throws, which is rethrown). Returns what
     * `$fn` returns. Ending a transaction's root span sends it with the next flush.
     *
     * ```php
     * $guidely->startSpan(['name' => 'nightly-export', 'op' => 'queue.process'], fn (Span $span) => $export->run());
     * ```
     *
     * @template T
     * @param array{name: string, op?: string, attributes?: array<string, mixed>, startTime?: float, parentSpan?: Span|null, forceTransaction?: bool, onlyIfParent?: bool} $options
     * @param callable(Span): T $fn
     * @return T
     */
    public function startSpan(array $options, callable $fn): mixed
    {
        return $this->tracer->run($this->safeSpan($options), $fn);
    }

    /**
     * A span that is not made active: end it yourself (`$span->end()`).
     *
     * @param array{name: string, op?: string, attributes?: array<string, mixed>, startTime?: float, parentSpan?: Span|null, forceTransaction?: bool, onlyIfParent?: bool} $options
     */
    public function startInactiveSpan(array $options): Span
    {
        return $this->safeSpan($options);
    }

    /**
     * A new transaction (a job, a command), active — the parent of later spans — until you end it.
     * It continues the trace of `continueTrace()` when one was given.
     *
     * @param array{name: string, op?: string, attributes?: array<string, mixed>, startTime?: float} $options
     */
    public function startTransaction(array $options): Span
    {
        $span = $this->safeSpan(['forceTransaction' => true] + $options);
        $this->tracer->activate($span);
        return $span;
    }

    /** The span of the running code (the innermost `startSpan()`, or the request / job transaction). */
    public function getActiveSpan(): ?Span
    {
        return $this->tracer->active();
    }

    /**
     * Continues an upstream trace (headers of a queue message or a request): the next transaction
     * joins it. Pass the headers by name (`sentry-trace`, `baggage`, `traceparent`, any case); an
     * empty array forgets the previous one. Returns whether they named a trace.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function continueTrace(array $headers): bool
    {
        return $this->tracer->continueTrace($headers);
    }

    /**
     * `sentry-trace`, `baggage` and `traceparent` for the active trace, to pass it to another service
     * by hand (cURL, a queue message). Empty without one.
     *
     * @return array{sentry-trace?: string, baggage?: string, traceparent?: string}
     */
    public function getTraceHeaders(): array
    {
        return $this->tracer->headers();
    }

    /**
     * Starts the `http.server` transaction of the request being served — from `$_SERVER` (null), a
     * PSR-7 request, or `['method' => …, 'url' => …, 'headers' => […]]` — continuing the caller's
     * trace. It stays active until `finishRequest()` or the end of the request (with
     * `http_response_code()`). Null when tracing is off or on the CLI.
     *
     * @param object|array{method?: string, url?: string, headers?: array<string, string|list<string>>}|null $request
     */
    public function traceRequest(object|array|null $request = null): ?Span
    {
        try {
            $r = match (true) {
                $request === null => RequestContext::fromGlobals()['request'] ?? null,
                is_object($request) => RequestContext::fromPsr7($request)['request'] ?? null,
                default => $request,
            };
            return is_array($r) ? $this->tracer->startRequest($r) : null;
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'traceRequest() failed: ' . $e->getMessage(), 1, null, $e));
            return null;
        }
    }

    /** Names the request transaction by the matched route (`/users/{id}` → `GET /users/{id}`). */
    public function setRoute(string $route): void
    {
        $this->tracer->setRoute($route);
    }

    /** Ends the request transaction with the response's status (default `http_response_code()`). */
    public function finishRequest(?int $status = null): void
    {
        $this->tracer->finishRequest($status);
    }

    /**
     * Records a query that ran: a `db` span under the active span (with tracing on) and a `query`
     * breadcrumb on later errors. For framework query events (Laravel's `DB::listen`, Doctrine
     * middlewares); literal values are taken out of the SQL and bindings are never read.
     *
     * @param array{system?: ?string, database?: ?string, host?: ?string, port?: ?int, rows?: ?int, error?: \Throwable|string|null} $options
     *     `system`: `mysql`, `postgresql`, `sqlite`… (or a PDO driver name)
     */
    public function recordQuery(string $sql, float $durationMs, array $options = []): void
    {
        try {
            $options['system'] = Sql::system(is_string($options['system'] ?? null) ? $options['system'] : null);
            $this->tracer->query($sql, $durationMs, $options);
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'recordQuery() failed: ' . $e->getMessage(), 1, null, $e));
        }
    }

    /**
     * Records every prepared statement a PDO connection executes (`prepare()` + `execute()`, what
     * Doctrine and most query builders run) by giving it a statement class. `PDO::query()` and
     * `exec()` are not seen: use recordQuery() for those. Returns false (and reports why) for
     * persistent connections and connections that already use their own statement class.
     */
    public function instrumentPdo(\PDO $pdo): bool
    {
        try {
            if ($pdo->getAttribute(\PDO::ATTR_PERSISTENT)) {
                $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'instrumentPdo(): persistent PDO connections cannot change their statement class; use recordQuery()'));
                return false;
            }
            $current = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
            $class = is_array($current) ? ($current[0] ?? \PDOStatement::class) : \PDOStatement::class;
            if ($class !== \PDOStatement::class && $class !== TracedPdoStatement::class) {
                $this->report(new TrackException(TrackException::INVALID_MESSAGE, "instrumentPdo(): the connection already uses its own statement class ($class); use recordQuery()"));
                return false;
            }
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $system = Sql::system(is_string($driver) ? $driver : null);
            $tracer = \WeakReference::create($this->tracer);
            $record = static function (string $sql, float $ms, ?\Throwable $error, ?int $rows) use ($tracer, $system): void {
                $tracer->get()?->query($sql, $ms, ['system' => $system, 'rows' => $rows, 'error' => $error, 'origin' => 'auto.db.pdo']);
            };
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TracedPdoStatement::class, [$record]]);
            return true;
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'instrumentPdo() failed: ' . $e->getMessage(), 1, null, $e));
            return false;
        }
    }

    /**
     * A Guzzle middleware: an `http.client` span per request (with tracing on) and trace headers on
     * requests that match `tracePropagationTargets`.
     *
     * ```php
     * $stack = HandlerStack::create();
     * $stack->push($guidely->guzzleMiddleware());
     * $client = new Client(['handler' => $stack]);
     * ```
     */
    public function guzzleMiddleware(): \Closure
    {
        return $this->tracer->guzzleMiddleware();
    }

    /** @param array<string, mixed> $options */
    private function safeSpan(array $options): Span
    {
        try {
            return $this->tracer->create($options);
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'Starting a span failed: ' . $e->getMessage(), 1, null, $e));
            return $this->tracer->create(['name' => 'span', 'parentSpan' => null, 'onlyIfParent' => true]);
        }
    }

    /**
     * Queues a finished transaction; one too large for a batch loses its last spans until it fits.
     *
     * @internal called by the tracer
     * @param array{messageId: string, timestamp: float, userId?: ?string, transaction: array<string, mixed>} $args
     */
    public function enqueueTransaction(array $args): void
    {
        $tx = $args['transaction'];
        $bytes = ErrorCapture::bytes($tx) + 200;
        while ($bytes > Messages::MAX_TRANSACTION_MESSAGE_BYTES && is_array($tx['spans'] ?? null) && $tx['spans'] !== []) {
            $n = count($tx['spans']);
            $keep = (int) floor($n * min(0.9, Messages::MAX_TRANSACTION_MESSAGE_BYTES / $bytes));
            $tx['spans'] = array_slice($tx['spans'], 0, $keep);
            $dropped = ($tx['contexts']['trace']['data']['guidely.dropped_spans'] ?? 0) + ($n - $keep);
            $tx['contexts']['trace']['data']['guidely.dropped_spans'] = $dropped;
            $bytes = ErrorCapture::bytes($tx) + 200;
        }
        $args['transaction'] = $tx;
        $args['timestamp'] = Messages::toDateTime($args['timestamp']);
        if (($args['userId'] ?? null) === null) {
            unset($args['userId']);
        }
        $this->enqueue('transaction', $args);
    }

    /**
     * Builds and queues one error. Never throws.
     *
     * @internal used by {@see ErrorHandler}
     * @param array<string, mixed> $context
     * @param array{source: string, mechanism: ?string, handled: bool, level?: ?Level} $how
     * @param list<array{file: ?string, line: ?int, function: ?string}>|null $frames
     */
    public function capture(?\Throwable $exception, ?string $type, ?string $message, array $context, array $how, ?array $frames = null): ?string
    {
        try {
            if ($this->closed) {
                $this->report(new TrackException(TrackException::CLOSED, 'An error was captured after close(); it was dropped'));
                return null;
            }
            $id = Messages::newMessageId();
            // Errors raised inside a trace link to it (the trace page lists them, the issue links back).
            $trace = $this->tracer->errorContext();
            if ($trace !== null && !isset($context['contexts']['trace'])) {
                $context['contexts'] = (is_array($context['contexts'] ?? null) ? $context['contexts'] : []) + ['trace' => $trace];
            }
            $args = $this->errors->build($exception, $type, $message, $context, $how, new \DateTimeImmutable('now'), $id, $frames);
            if ($args === null) {
                return null;
            }
            return $this->enqueue('exception', $args) ? $id : null;
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, 'Capturing an error failed: ' . $e->getMessage(), 1, null, $e));
            return null;
        }
    }

    private function enqueue(string $type, mixed $message): bool
    {
        try {
            if ($this->closed) {
                $this->report(new TrackException(TrackException::CLOSED, "$type() was called after close(); the message was dropped"));
                return false;
            }
            $json = Messages::build($type, $message, new \DateTimeImmutable('now'));
            $bytes = strlen($json);
            $max = match ($type) {
                'exception' => Messages::MAX_EXCEPTION_MESSAGE_BYTES,
                'transaction' => Messages::MAX_TRANSACTION_MESSAGE_BYTES,
                default => Messages::MAX_MESSAGE_BYTES,
            };
            if ($bytes > $max) {
                $this->report(new TrackException(TrackException::MESSAGE_TOO_LARGE, "$type() message is $bytes bytes, more than the $max-byte limit; it was dropped"));
                return false;
            }
            if (count($this->queue) >= $this->maxQueueSize) {
                array_shift($this->queue);
                $this->droppedSinceReport++;
            }
            $this->queue[] = [$json, $bytes];
            if (count($this->queue) >= $this->flushAt) {
                $this->send(false);
            }
            return true;
        } catch (\InvalidArgumentException $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, "Invalid $type() call dropped: " . $e->getMessage()));
            return false;
        } catch (\Throwable $e) {
            $this->report(new TrackException(TrackException::INVALID_MESSAGE, "$type() failed: " . $e->getMessage(), 1, null, $e));
            return false;
        }
    }

    /** Sends batches from the head of the queue: everything (`$all`) or only full ones. */
    private function send(bool $all): bool
    {
        if ($this->sending) {
            return true; // re-entered from onError: the running loop sends the rest
        }
        $this->sending = true;
        try {
            $ok = true;
            while (true) {
                $this->reportDrops();
                $n = count($this->queue);
                if ($n === 0 || (!$all && $n < $this->batchCap)) {
                    break;
                }
                $ok = $this->deliver($this->takeBatch()) && $ok;
            }
            return $ok;
        } finally {
            $this->sending = false;
        }
    }

    /** @return list<string> up to batchCap messages and MAX_BATCH_BYTES bytes (at least one) */
    private function takeBatch(): array
    {
        $batch = [];
        $size = 0;
        while ($this->queue !== [] && count($batch) < $this->batchCap) {
            [$json, $bytes] = $this->queue[0];
            if ($batch !== [] && $size + $bytes > Messages::MAX_BATCH_BYTES) {
                break;
            }
            $size += $bytes;
            $batch[] = $json;
            array_shift($this->queue);
        }
        return $batch;
    }

    /** @param list<string> $batch */
    private function deliver(array $batch): bool
    {
        $messages = implode(',', $batch);
        $n = count($batch);
        $sdk = json_encode(['name' => self::SDK_NAME, 'version' => self::VERSION], Messages::JSON_FLAGS);
        for ($attempt = 1; ; $attempt++) {
            $body = '{"batch":[' . $messages . '],"sentAt":"' . Messages::formatTimestamp(new \DateTimeImmutable('now')) . '","sdk":' . $sdk . '}';
            $status = 0;
            $retryAfter = null;
            $failure = null;
            try {
                $res = $this->http->send('POST', $this->url, $this->headers, $body, $this->timeoutSeconds);
                $status = $res->status;
                $retryAfter = $res->header('retry-after');
            } catch (\Throwable $e) {
                $failure = $e;
            }
            $action = Retry::action($status);
            if ($action === Retry::OK) {
                return true;
            }
            if ($action === Retry::DROP || $attempt > $this->maxRetries) {
                $this->report(new TrackException(TrackException::DELIVERY_FAILED, self::failureMessage($status, $failure, $n, $attempt), $n, $status, $failure));
                return false;
            }
            $delay = Retry::retryAfterMs($retryAfter) !== null
                ? Retry::delayMs($attempt, $retryAfter, $this->retryBaseDelayMs, $this->retryMaxDelayMs, $this->retryMaxDelayMs)
                : Retry::jitter(Retry::delayMs($attempt, null, $this->retryBaseDelayMs, $this->retryMaxDelayMs, $this->retryMaxDelayMs), ($this->random)());
            ($this->sleep)($delay);
        }
    }

    private static function failureMessage(int $status, ?\Throwable $failure, int $count, int $attempts): string
    {
        $what = "$count messages were dropped";
        if ($status === 0) {
            return "$what: Guidely could not be reached in $attempts attempt" . ($attempts === 1 ? '' : 's') . ' (' . ($failure?->getMessage() ?? 'no response') . ')';
        }
        if ($status === 401 || $status === 403) {
            return "$what: the key was rejected (HTTP $status). Check it is a gly_sk_ key with the events:write scope for this environment";
        }
        return "$what: HTTP $status" . ($attempts > 1 ? " after $attempts attempts" : '');
    }

    private function reportDrops(): void
    {
        if ($this->droppedSinceReport === 0) {
            return;
        }
        $n = $this->droppedSinceReport;
        $this->droppedSinceReport = 0;
        $this->report(new TrackException(TrackException::QUEUE_FULL, "The queue was full (maxQueueSize {$this->maxQueueSize}): dropped the $n oldest messages", $n));
    }

    private function report(TrackException $error): void
    {
        $handled = false;
        if ($this->onError !== null) {
            $handled = true;
            try {
                ($this->onError)($error);
            } catch (\Throwable) {
                // an error handler must never break the caller
            }
        }
        if ($this->logger !== null) {
            $handled = true;
            try {
                $level = $error->errorCode === TrackException::DELIVERY_FAILED ? 'error' : 'warning';
                $level = method_exists($this->logger, $level) ? $level : 'error';
                $this->logger->{$level}('[guidely-php] ' . $error->getMessage(), ['exception' => $error]);
            } catch (\Throwable) {
            }
        }
        if (!$handled) {
            error_log('[guidely-php] ' . $error->getMessage());
        }
    }
}
