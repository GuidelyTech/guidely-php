<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Cache;

use GuidelyTech\Flags\Exception\FlagsException;

/** Shared memory of the PHP-FPM pool via ext-apcu: the fastest option on a single host. */
final class ApcuCache implements CacheInterface
{
    public function __construct(private readonly string $prefix = 'guidely-flags:')
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_enabled') || !apcu_enabled()) {
            throw new FlagsException('ApcuCache needs ext-apcu enabled (apc.enabled=1, and apc.enable_cli=1 on the CLI)');
        }
    }

    public function get(string $key): mixed
    {
        $value = apcu_fetch($this->prefix . $key, $ok);
        return $ok ? $value : null;
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        apcu_store($this->prefix . $key, $value, $ttlSeconds ?? 0);
    }
}
