<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Cache;

/**
 * Where the SDK keeps the downloaded payload between requests. Values are JSON-compatible arrays.
 *
 * Shipped implementations: {@see FileCache} (default), {@see ApcuCache}, {@see InMemoryCache} and
 * {@see Psr16Cache} (wraps any PSR-16 cache such as Redis or Memcached). Implementations should not
 * throw for a missing key; errors they throw are reported through `onError` and otherwise ignored.
 */
interface CacheInterface
{
    /** The stored value, or null when missing or expired. */
    public function get(string $key): mixed;

    /** Store a value; `null` TTL = keep until replaced. */
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void;
}
