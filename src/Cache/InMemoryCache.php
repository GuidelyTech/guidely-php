<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Cache;

/** Per-instance memory cache: nothing survives the PHP process. Useful in tests and long-running workers. */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: ?float}> */
    private array $items = [];

    /** @param (callable(): float)|null $clock seconds since the epoch (tests) */
    public function __construct(private readonly mixed $clock = null)
    {
    }

    public function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expires'] !== null && $item['expires'] <= $this->now()) {
            unset($this->items[$key]);
            return null;
        }
        return $item['value'];
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $this->items[$key] = ['value' => $value, 'expires' => $ttlSeconds === null ? null : $this->now() + $ttlSeconds];
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}
