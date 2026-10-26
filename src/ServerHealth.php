<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

use GuidelyTech\Flags\Cache\CacheInterface;

/**
 * Whether the Guidely API is reachable right now: a circuit breaker shared by every PHP worker
 * through the payload cache (the PHP side of packages/sdk-core/src/health.ts).
 *
 * Every request's outcome is reported here. After 3 failures in a row (network errors, timeouts,
 * 429, 5xx) the circuit opens for a cooldown that doubles with each further failure (5 s up to
 * 5 min, ±20% jitter so workers don't retry in step); a `Retry-After` answer (capped at 1 h) sets
 * the cooldown directly. Any other answer closes it. While it is open no worker sends anything,
 * so a cold worker serves fallbacks at once instead of blocking for the timeout. When a cooldown
 * ends, the first worker to notice holds the circuit for the length of its probe so the others
 * don't all try at once.
 *
 * The record (`{failures, openUntil}`) lives under its own key next to the payload. Updates are
 * read-modify-write without locking: concurrent failures can be counted once, which only delays
 * opening by a request or two. Within the process a failure also holds requests back for at least
 * 5 s, so the getters of one web request never retry a server that just failed them.
 *
 * @internal used by {@see GuidelyFlags}
 */
final class ServerHealth
{
    public const THRESHOLD = 3;
    public const BASE_SECONDS = 5.0;
    public const MAX_SECONDS = 300.0;
    /** Longest `Retry-After` honoured, so a bad header can't silence the SDK for days. */
    public const MAX_RETRY_AFTER_SECONDS = 3600.0;
    /** How long a record outlives its cooldown in the cache before it is forgotten. */
    private const RECORD_TTL_SECONDS = 3600;

    /** @var array{failures: int, openUntil: float} last known shared record */
    private array $state = ['failures' => 0, 'openUntil' => 0.0];
    /** This process holds requests back until then, whatever the shared record says. */
    private float $localOpenUntil = 0.0;

    /**
     * @param \Closure(): float $now
     * @param float $probeSeconds how long a probe may take (the HTTP timeout)
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $key,
        private readonly \Closure $now,
        private readonly float $probeSeconds,
    ) {
    }

    /**
     * Seconds to wait before sending (0: send now). When a cooldown has just ended this caller
     * becomes the probe: the shared circuit stays held while its request is in flight.
     */
    public function waitSeconds(): float
    {
        $now = (float) ($this->now)();
        if ($this->localOpenUntil > $now) {
            return $this->localOpenUntil - $now;
        }
        $this->state = $this->read();
        if ($this->state['openUntil'] > $now) {
            return $this->state['openUntil'] - $now;
        }
        if ($this->state['openUntil'] > 0) {
            $this->state['openUntil'] = $now + $this->probeSeconds + 1;
            $this->write($now);
        }
        return 0.0;
    }

    /** Failed requests in a row, as last seen. */
    public function failures(): int
    {
        return $this->state['failures'];
    }

    /** The server answered (2xx, 3xx, or a 4xx other than 429): it is up. */
    public function success(): void
    {
        $this->localOpenUntil = 0.0;
        $this->state = $this->read();
        if ($this->state['failures'] > 0 || $this->state['openUntil'] > 0) {
            $this->state = ['failures' => 0, 'openUntil' => 0.0];
            $this->write((float) ($this->now)());
        }
    }

    /** The request failed or the server is overloaded. `$retryAfterSeconds` comes from `Retry-After`. */
    public function failure(?float $retryAfterSeconds = null): void
    {
        $now = (float) ($this->now)();
        // Re-read: other workers may have counted failures while this request was in flight.
        $state = $this->read();
        $state['failures']++;
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $state['openUntil'] = max($state['openUntil'], $now + min($retryAfterSeconds, self::MAX_RETRY_AFTER_SECONDS));
        } elseif ($state['failures'] >= self::THRESHOLD) {
            $cooldown = min(self::MAX_SECONDS, self::BASE_SECONDS * 2 ** min($state['failures'] - self::THRESHOLD, 16));
            $state['openUntil'] = max($state['openUntil'], $now + $cooldown * (0.8 + mt_rand() / mt_getrandmax() * 0.4));
        }
        $this->state = $state;
        $this->localOpenUntil = max($state['openUntil'], $now + self::BASE_SECONDS);
        $this->write($now);
    }

    /** `Retry-After` in seconds: delta-seconds or an HTTP date. Null when absent or unparseable. */
    public static function parseRetryAfter(?string $value, float $now): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $at = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        return $at === false ? null : max(0.0, $at->getTimestamp() - $now);
    }

    /** Answers that mean the server is down or shedding load (retry later) rather than refusing the request. */
    public static function isServerFailure(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /** @return array{failures: int, openUntil: float} */
    private function read(): array
    {
        try {
            $record = $this->cache->get($this->key);
        } catch (\Throwable) {
            // A broken cache is reported by the payload reads; this process still remembers its own failures.
            return $this->state;
        }
        if (!is_array($record) || !is_numeric($record['failures'] ?? null) || !is_numeric($record['openUntil'] ?? null)) {
            return ['failures' => 0, 'openUntil' => 0.0];
        }
        return ['failures' => max(0, (int) $record['failures']), 'openUntil' => (float) $record['openUntil']];
    }

    private function write(float $now): void
    {
        try {
            $this->cache->set($this->key, $this->state, (int) ceil(max(0.0, $this->state['openUntil'] - $now)) + self::RECORD_TTL_SECONDS);
        } catch (\Throwable) {
            // see read()
        }
    }
}
