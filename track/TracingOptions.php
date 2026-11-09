<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Performance tracing settings of a {@see GuidelyTrack} client (its `tracing:` argument), named like
 * Sentry's options (docs/error-monitoring/performance.md). Tracing is off unless `tracesSampleRate`
 * or `tracesSampler` is set.
 *
 * ```php
 * $guidely = new GuidelyTrack(getenv('GUIDELY_SECRET_KEY'), environment: 'production', tracing: new TracingOptions(
 *     tracesSampleRate: 0.2,
 *     ignoreTransactions: ['GET /health'],
 * ));
 * ```
 */
final class TracingOptions
{
    public const MAX_SPANS = 1000;

    public readonly ?float $tracesSampleRate;
    /** @var (\Closure(array): (float|bool))|null */
    public readonly ?\Closure $tracesSampler;
    public readonly int $maxSpans;

    /**
     * @param float|null $tracesSampleRate share of transactions recorded, 0–1
     * @param callable(array): (float|bool)|null $tracesSampler decides per transaction from
     *     `['name' => …, 'op' => …, 'attributes' => […], 'parentSampled' => ?bool]`: a rate or true / false.
     *     Takes precedence over `tracesSampleRate`; without it, an upstream service's decision is followed.
     * @param list<string>|null $tracePropagationTargets outgoing requests that get `sentry-trace`,
     *     `baggage` and `traceparent` headers: strings match anywhere in the URL, strings starting with
     *     `/` and ending with `/` (or another delimiter) are regular expressions. Null: every request.
     * @param list<string> $ignoreTransactions transactions not sent: substrings of the name, or regular expressions
     * @param int $maxSpans child spans kept per transaction, at most 1000
     */
    public function __construct(
        ?float $tracesSampleRate = null,
        ?callable $tracesSampler = null,
        public readonly ?array $tracePropagationTargets = null,
        public readonly array $ignoreTransactions = [],
        int $maxSpans = self::MAX_SPANS,
    ) {
        $this->tracesSampleRate = $tracesSampleRate === null || is_nan($tracesSampleRate) ? null : max(0.0, min(1.0, $tracesSampleRate));
        $this->tracesSampler = $tracesSampler === null ? null : \Closure::fromCallable($tracesSampler);
        $this->maxSpans = max(0, min(self::MAX_SPANS, $maxSpans));
    }

    public function enabled(): bool
    {
        return $this->tracesSampleRate !== null || $this->tracesSampler !== null;
    }

    /** Whether `$value` matches one of the patterns: a regular expression (`/…/`, `#…#`, `~…~`) or a substring. */
    public static function matches(array $patterns, string $value): bool
    {
        foreach ($patterns as $p) {
            if (!is_string($p) || $p === '') {
                continue;
            }
            if (strlen($p) > 2 && preg_match('/^([\/#~]).*\1[imsxuU]*$/s', $p) === 1) {
                if (@preg_match($p, $value) === 1) {
                    return true;
                }
            } elseif (str_contains($value, $p)) {
                return true;
            }
        }
        return false;
    }
}
