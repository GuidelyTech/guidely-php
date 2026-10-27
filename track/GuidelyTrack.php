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
        if ($flushOnShutdown) {
            // A weak reference: the shutdown function must not keep the client alive past unset()
            // (its destructor flushes then).
            $ref = \WeakReference::create($this);
            register_shutdown_function(static function () use ($ref): void {
                $ref->get()?->flush();
            });
        }
    }

    public function __destruct()
    {
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

    /** Flushes, then drops (and reports) later calls. */
    public function close(): void
    {
        $this->flush();
        $this->closed = true;
    }

    /** Messages waiting to be sent. */
    public function queuedCount(): int
    {
        return count($this->queue);
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
            if ($bytes > Messages::MAX_MESSAGE_BYTES) {
                $this->report(new TrackException(TrackException::MESSAGE_TOO_LARGE, "$type() message is $bytes bytes, more than the " . Messages::MAX_MESSAGE_BYTES . '-byte limit; it was dropped'));
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
