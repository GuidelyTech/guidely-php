<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * How an answer to a batch is handled (sdks/conformance/track-vectors.json → responses). Status 0
 * means no answer (network error or timeout).
 *
 * @internal
 */
final class Retry
{
    public const OK = 'ok';
    public const RETRY = 'retry';
    public const DROP = 'drop';

    /** 2xx → ok; no answer, 429 and 5xx → retry; anything else → drop. */
    public static function action(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return self::OK;
        }
        if ($status === 0 || $status === 429 || ($status >= 500 && $status < 600)) {
            return self::RETRY;
        }
        return self::DROP;
    }

    /** Retry-After in whole seconds, in milliseconds; null for anything else (HTTP dates too). */
    public static function retryAfterMs(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        if ($v === '' || !ctype_digit($v)) {
            return null;
        }
        return strlen($v) > 12 ? PHP_INT_MAX : (int) $v * 1000;
    }

    /**
     * The wait before retrying after failed attempt `$attempt` (1 = the first send), before jitter:
     * `min($baseMs · 2^(attempt−1), $maxMs)`, or Retry-After capped at `$retryAfterMaxMs`.
     */
    public static function delayMs(int $attempt, ?string $retryAfter, int $baseMs, int $maxMs, int $retryAfterMaxMs): int
    {
        $ra = self::retryAfterMs($retryAfter);
        if ($ra !== null) {
            return min($ra, $retryAfterMaxMs);
        }
        $shift = max(0, min($attempt - 1, 30));
        return (int) min($baseMs * (2 ** $shift), $maxMs);
    }

    /** ±20 % jitter on a computed backoff; `$random` is in [0, 1). */
    public static function jitter(int $delayMs, float $random): int
    {
        return (int) round($delayMs * (0.8 + 0.4 * $random));
    }
}
