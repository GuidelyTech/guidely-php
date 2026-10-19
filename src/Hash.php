<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * Hashing shared by every Guidely flag SDK (docs/feature-flags-spec.md §3.4, §3.3).
 * Mirrors packages/flags/src/hash.ts. PHP strings are byte strings, so "UTF-8 bytes of x" is
 * simply `x` for the (UTF-8) strings the SDK works with.
 */
final class Hash
{
    /**
     * 32-bit FNV-1a over the bytes of `$bytes`, as an unsigned integer.
     *
     * Uses ext-hash's `fnv1a32` (always bundled with PHP), which computes exactly
     * `h = ((h XOR b) * 0x01000193) mod 2^32` from `0x811C9DC5`, so there is no risk of PHP's
     * signed 64-bit integers overflowing into floats.
     */
    public static function fnv1a32(string $bytes): int
    {
        /** @var array{1: int} $u */
        $u = unpack('N', hash('fnv1a32', $bytes, true));
        return $u[1];
    }

    /** Deterministic bucket in [0, 1) with 4 decimals (GrowthBook hash v2 for ASCII input). */
    public static function bucket(string $seed, string $value): float
    {
        $inner = self::fnv1a32($seed . $value);
        return (self::fnv1a32((string) $inner) % 10000) / 10000.0;
    }

    /** Lowercase hex SHA-256 of the bytes of `$input`. */
    public static function sha256Hex(string $input): string
    {
        return hash('sha256', $input);
    }

    /** `H(x)` for the hashed operators: SHA-256 of `salt + value`. */
    public static function confidential(string $salt, string $value): string
    {
        return hash('sha256', $salt . $value);
    }

    /** Comparison value for the hashed prefix/suffix operators: `"<byteLength>_<H(part)>"`. */
    public static function confidentialAffix(string $salt, string $part): string
    {
        return strlen($part) . '_' . self::confidential($salt, $part);
    }
}
