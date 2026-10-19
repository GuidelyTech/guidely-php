<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Cache;

/**
 * Adapter for any PSR-16 `Psr\SimpleCache\CacheInterface` (Laravel's `Cache::store()`, Symfony's
 * `Psr16Cache`, Redis / Memcached implementations). Duck-typed so the SDK does not depend on
 * psr/simple-cache: any object with `get($key, $default)` and `set($key, $value, $ttl)` works.
 */
final class Psr16Cache implements CacheInterface
{
    public function __construct(private readonly object $cache)
    {
        if (!method_exists($cache, 'get') || !method_exists($cache, 'set')) {
            throw new \InvalidArgumentException('The cache must implement get($key, $default) and set($key, $value, $ttl) (PSR-16)');
        }
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key, null);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->cache->set($key, $value, $ttlSeconds);
    }
}
