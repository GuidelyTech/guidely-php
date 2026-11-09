<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * A unit of work: a request, a job, a query, a call to another service, a step of your own. Ending the
 * root span of a transaction sends the transaction. Spans that are not sampled record nothing but
 * still carry the trace to other services. Statuses are Sentry's (`ok`, `internal_error`, `not_found`…).
 */
final class Span
{
    public const STATUSES = ['ok', 'cancelled', 'unknown', 'invalid_argument', 'deadline_exceeded', 'not_found', 'already_exists', 'permission_denied', 'resource_exhausted', 'failed_precondition', 'aborted', 'out_of_range', 'unimplemented', 'internal_error', 'unavailable', 'data_loss', 'unauthenticated'];
    private const MAX_ATTRIBUTES = 100;

    /** @var array<string, string|int|float|bool|list<string|int|float|bool>> */
    private array $attributes = [];
    private ?string $status = null;
    private ?float $endTime = null;

    /**
     * @internal created by the client ({@see GuidelyTrack::startSpan()})
     * @param array<string, string> $dsc the trace's dynamic sampling context (`baggage`)
     * @param \Closure(Span): void|null $onEnd
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        /** Null for an upstream span that did not say (the decision is ours). */
        public readonly ?bool $sampled,
        private string $name,
        private ?string $op,
        public readonly string $origin,
        public readonly float $startTime,
        public readonly ?Segment $segment,
        public readonly bool $isRoot,
        public readonly array $dsc,
        /** An upstream service's span (`continueTrace()`, an incoming request): never recorded. */
        public readonly bool $remote = false,
        private readonly ?\Closure $onEnd = null,
    ) {
    }

    /** @return array{traceId: string, spanId: string, traceFlags: int} */
    public function spanContext(): array
    {
        return ['traceId' => $this->traceId, 'spanId' => $this->spanId, 'traceFlags' => $this->sampled ? 1 : 0];
    }

    public function isRecording(): bool
    {
        return $this->sampled === true && $this->segment !== null && !$this->segment->finished && $this->endTime === null;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function op(): ?string
    {
        return $this->op;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function endTime(): ?float
    {
        return $this->endTime;
    }

    /** @return array<string, string|int|float|bool|list<string|int|float|bool>> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** A string, number, bool or list of them; null removes it. Keys that look like credentials are dropped. */
    public function setAttribute(string $key, mixed $value): self
    {
        if ($key === '' || preg_match(ErrorCapture::SENSITIVE, $key) === 1) {
            return $this;
        }
        $key = ErrorCapture::cut($key, 200);
        if ($value === null) {
            unset($this->attributes[$key]);
            return $this;
        }
        $v = self::value($value);
        if ($v === null || (!array_key_exists($key, $this->attributes) && count($this->attributes) >= self::MAX_ATTRIBUTES)) {
            return $this;
        }
        $this->attributes[$key] = $v;
        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $k => $v) {
            if (is_string($k)) {
                $this->setAttribute($k, $v);
            }
        }
        return $this;
    }

    /** A Sentry status (`internal_error`, `not_found`…); unknown ones are ignored. */
    public function setStatus(string $status): self
    {
        if (in_array($status, self::STATUSES, true)) {
            $this->status = $status;
        }
        return $this;
    }

    /** The status for an HTTP status code (`404` → `not_found`), plus `http.response.status_code`. */
    public function setHttpStatus(int $code): self
    {
        if ($code < 100 || $code > 599) {
            return $this;
        }
        $this->attributes['http.response.status_code'] = $code;
        $this->status = self::statusFromHttp($code);
        return $this;
    }

    public function updateName(string $name): self
    {
        if ($name !== '') {
            $this->name = ErrorCapture::cut($name, 2000);
        }
        return $this;
    }

    /** Ends the span (later calls do nothing). `$endTime`: Unix seconds (default: now). */
    public function end(?float $endTime = null): void
    {
        if ($this->endTime !== null) {
            return;
        }
        $this->endTime = max($this->startTime, $endTime ?? microtime(true));
        if ($this->onEnd !== null) {
            ($this->onEnd)($this);
        }
    }

    /** @internal a finished child span in Sentry's transaction format */
    public function toArray(): array
    {
        $out = [
            'span_id' => $this->spanId,
            'trace_id' => $this->traceId,
            'description' => $this->name,
            'start_timestamp' => round($this->startTime, 6),
            'timestamp' => round($this->endTime ?? $this->startTime, 6),
            'status' => $this->status ?? 'ok',
            'origin' => $this->origin,
        ];
        if ($this->parentSpanId !== null) {
            $out['parent_span_id'] = $this->parentSpanId;
        }
        if ($this->op !== null) {
            $out['op'] = $this->op;
        }
        if ($this->attributes !== []) {
            $out['data'] = $this->attributes;
        }
        return $out;
    }

    /** Sentry's getSpanStatusFromHttpCode. */
    public static function statusFromHttp(int $code): string
    {
        if ($code < 400) {
            return 'ok';
        }
        return [401 => 'unauthenticated', 403 => 'permission_denied', 404 => 'not_found', 409 => 'already_exists', 413 => 'failed_precondition', 429 => 'resource_exhausted', 499 => 'cancelled', 501 => 'unimplemented', 503 => 'unavailable', 504 => 'deadline_exceeded'][$code] ?? ($code < 500 ? 'invalid_argument' : 'internal_error');
    }

    /** @return string|int|float|bool|list<string|int|float|bool>|null */
    private static function value(mixed $v): string|int|float|bool|array|null
    {
        if (is_string($v)) {
            return ErrorCapture::cut($v, 2000);
        }
        if (is_int($v) || is_bool($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if ($v instanceof \Stringable) {
            return ErrorCapture::cut((string) $v, 2000);
        }
        if (is_array($v) && array_is_list($v)) {
            $out = [];
            foreach (array_slice($v, 0, 100) as $x) {
                if (is_string($x)) {
                    $out[] = ErrorCapture::cut($x, 200);
                } elseif (is_int($x) || is_bool($x) || (is_float($x) && is_finite($x))) {
                    $out[] = $x;
                }
            }
            return $out;
        }
        return null;
    }
}
