<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

use GuidelyTech\Flags\Exception\CycleException;
use GuidelyTech\Flags\Sticky\StickyBucketServiceInterface;

/**
 * Pure, synchronous flag evaluation — a port of packages/flags/src/evaluate.ts, the reference
 * implementation of docs/feature-flags-spec.md §3. No I/O: give it a payload and attributes, get a
 * result. Method names and order mirror the TypeScript file so changes are easy to port.
 */
final class Evaluator
{
    private const MAX_DEPTH = 32;

    /** @var array<string, string|null> compiled PCRE pattern per JS pattern (null = invalid) */
    private static array $regexCache = [];

    /** @var list<string> */
    private array $stack = [];

    /**
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed>|object $attributes
     */
    private function __construct(
        private readonly array $payload,
        private readonly array|object $attributes,
        private readonly float $now,
        private readonly ?StickyBucketServiceInterface $sticky,
    ) {
    }

    /**
     * Evaluate one flag. Never throws: problems come back as reason `ERROR`.
     *
     * @param array<array-key, mixed>|object $payload the flags payload (spec §2)
     * @param array<array-key, mixed>|object $attributes
     * @param \DateTimeInterface|int|float|string|null $now evaluation time: a date, Unix seconds
     *        or an ISO 8601 string (default: the current time)
     */
    public static function evaluate(
        array|object $payload,
        string $key,
        array|object $attributes = [],
        \DateTimeInterface|int|float|string|null $now = null,
        ?StickyBucketServiceInterface $stickyBuckets = null,
    ): EvaluationResult {
        $ctx = new self(Json::asMap($payload), $attributes, self::nowOf($now), $stickyBuckets);
        try {
            return $ctx->evalFlag($key);
        } catch (\Throwable $e) {
            return EvaluationResult::error($key, $e->getMessage());
        }
    }

    /**
     * Evaluate every flag in the payload.
     *
     * @param array<array-key, mixed>|object $payload
     * @param array<array-key, mixed>|object $attributes
     * @return array<string, EvaluationResult>
     */
    public static function evaluateAll(
        array|object $payload,
        array|object $attributes = [],
        \DateTimeInterface|int|float|string|null $now = null,
        ?StickyBucketServiceInterface $stickyBuckets = null,
    ): array {
        $out = [];
        $nowMs = self::nowOf($now) / 1000.0;
        foreach (array_keys(Json::asMap(Json::asMap($payload)['flags'] ?? null)) as $key) {
            $key = (string) $key;
            $out[$key] = self::evaluate($payload, $key, $attributes, $nowMs, $stickyBuckets);
        }
        return $out;
    }

    /** Evaluation time in epoch milliseconds. */
    private static function nowOf(\DateTimeInterface|int|float|string|null $now): float
    {
        if ($now === null) {
            return microtime(true) * 1000.0;
        }
        return Values::instantOf($now) ?? microtime(true) * 1000.0;
    }

    /**
     * Attribute by literal key, then by dot path. `null` counts as missing (returns null).
     *
     * @param array<array-key, mixed>|object $attributes
     */
    public static function lookupAttribute(array|object $attributes, string $name): mixed
    {
        $map = Json::asMap($attributes);
        if (array_key_exists($name, $map)) {
            return $map[$name];
        }
        if (!str_contains($name, '.')) {
            return null;
        }
        $cur = $attributes;
        foreach (explode('.', $name) as $part) {
            if ($cur instanceof \stdClass || (is_array($cur) && !array_is_list($cur))) {
                $m = Json::asMap($cur);
                if (!array_key_exists($part, $m)) {
                    return null;
                }
                $cur = $m[$part];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** Text form of a hashing attribute, or `null` when missing or empty. */
    private function hashValueOf(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $t = Values::textOf(self::lookupAttribute($this->attributes, $name));
        return $t === null || $t === '' ? null : $t;
    }

    public static function isScheduleActive(mixed $schedule, float $now): bool
    {
        if (!Values::truthy($schedule)) {
            return true;
        }
        $schedule = Json::asMap($schedule);
        if (Values::truthy($schedule['start'] ?? null)) {
            $start = Values::instantOf($schedule['start']);
            if ($start === null || $now < $start) {
                return false;
            }
        }
        if (Values::truthy($schedule['end'] ?? null)) {
            $end = Values::instantOf($schedule['end']);
            if ($end === null || $now >= $end) {
                return false;
            }
        }
        return true;
    }

    private static function clamp01(mixed $n): float
    {
        return (is_int($n) || is_float($n)) && is_finite((float) $n) ? min(1.0, max(0.0, (float) $n)) : 0.0;
    }

    /** @param array<array-key, mixed> $rule */
    public static function effectiveCoverage(array $rule, float $now): float
    {
        $coverage = self::clamp01($rule['coverage'] ?? null);
        $latest = -INF;
        foreach (self::each($rule['ramp'] ?? null) as $step) {
            $step = Json::asMap($step);
            $at = Values::instantOf($step['at'] ?? null);
            if ($at !== null && $at <= $now && $at >= $latest) {
                $latest = $at;
                $coverage = self::clamp01($step['coverage'] ?? null);
            }
        }
        return $coverage;
    }

    /** Compile a pattern (common ECMAScript / PCRE subset) to PCRE; null when invalid. */
    private static function compile(string $pattern, bool $ci): ?string
    {
        $k = ($ci ? 'i' : '-') . ':' . $pattern;
        if (array_key_exists($k, self::$regexCache)) {
            return self::$regexCache[$k];
        }
        $re = '/' . self::translatePattern($pattern) . '/uD' . ($ci ? 'i' : '');
        if (@preg_match($re, '') === false) {
            $re = null;
        }
        if (count(self::$regexCache) > 500) {
            self::$regexCache = [];
        }
        self::$regexCache[$k] = $re;
        return $re;
    }

    /** Escape the `/` delimiter and map `\uXXXX` escapes to PCRE's `\x{XXXX}`. */
    private static function translatePattern(string $p): string
    {
        $out = '';
        $n = strlen($p);
        for ($i = 0; $i < $n; $i++) {
            $c = $p[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $d = $p[$i + 1];
                if ($d === 'u' && preg_match('/\G[0-9A-Fa-f]{4}/', $p, $m, 0, $i + 2) === 1) {
                    $out .= '\\x{' . $m[0] . '}';
                    $i += 5;
                    continue;
                }
                $out .= $c . $d;
                $i++;
                continue;
            }
            $out .= $c === '/' ? '\\/' : $c;
        }
        return $out;
    }

    private static function lower(string $s, bool $ci): string
    {
        return $ci ? Values::lower($s) : $s;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function textValues(array $values, bool $ci): array
    {
        $out = [];
        foreach ($values as $v) {
            $t = Values::textOf($v);
            if ($t !== null) {
                $out[] = self::lower($t, $ci);
            }
        }
        return $out;
    }

    /** @param list<mixed> $values */
    private static function affixMatches(string $salt, string $text, array $values, bool $suffix): bool
    {
        $len = strlen($text);
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $sep = strpos($v, '_');
            if ($sep === false || $sep <= 0) {
                continue;
            }
            $n = Values::jsNumber(substr($v, 0, $sep));
            if (!Values::isInteger($n) || $n < 0 || $n > $len) {
                continue;
            }
            $n = (int) $n;
            $part = $suffix ? substr($text, $len - $n) : substr($text, 0, $n);
            if (Hash::confidential($salt, $part) === substr($v, $sep + 1)) {
                return true;
            }
        }
        return false;
    }

    private function salt(): string
    {
        return Values::textOf($this->payload['salt'] ?? null) ?? '';
    }

    /** @param array<array-key, mixed> $c */
    private function evalAttribute(array $c): bool
    {
        $raw = is_string($c['attribute'] ?? null) ? self::lookupAttribute($this->attributes, $c['attribute']) : null;
        $values = Json::asList($c['values'] ?? null);
        $op = $c['operator'] ?? null;
        if ($op === 'notExists') {
            return $raw === null;
        }
        if ($raw === null) {
            return false;
        }
        $ci = Values::truthy($c['caseInsensitive'] ?? null);
        switch ($op) {
            case 'exists':
                return true;
            case 'isType':
                return Values::jsonTypeOf($raw) === ($values[0] ?? null);
            case 'isTrue':
                return $raw === true || (is_string($raw) && Values::lower($raw) === 'true');
            case 'isFalse':
                return $raw === false || (is_string($raw) && Values::lower($raw) === 'false');
            case 'equals':
            case 'notEquals':
            case 'in':
            case 'notIn':
            case 'contains':
            case 'notContains':
            case 'startsWith':
            case 'notStartsWith':
            case 'endsWith':
            case 'notEndsWith':
                $t0 = Values::textOf($raw);
                if ($t0 === null) {
                    return false;
                }
                $t = self::lower($t0, $ci);
                $vs = self::textValues($values, $ci);
                switch ($op) {
                    case 'equals':
                        return $vs !== [] && $t === $vs[0];
                    case 'notEquals':
                        return $vs !== [] && $t !== $vs[0];
                    case 'in':
                        return in_array($t, $vs, true);
                    case 'notIn':
                        return !in_array($t, $vs, true);
                    case 'contains':
                        return self::some($vs, static fn (string $v) => str_contains($t, $v));
                    case 'notContains':
                        return !self::some($vs, static fn (string $v) => str_contains($t, $v));
                    case 'startsWith':
                        return self::some($vs, static fn (string $v) => str_starts_with($t, $v));
                    case 'notStartsWith':
                        return !self::some($vs, static fn (string $v) => str_starts_with($t, $v));
                    case 'endsWith':
                        return self::some($vs, static fn (string $v) => str_ends_with($t, $v));
                    default:
                        return !self::some($vs, static fn (string $v) => str_ends_with($t, $v));
                }
            case 'regex':
            case 'notRegex':
                $t = Values::textOf($raw);
                if ($t === null) {
                    return false;
                }
                $res = [];
                foreach ($values as $v) {
                    $re = is_string($v) ? self::compile($v, $ci) : null;
                    if ($re === null) {
                        return false;
                    }
                    $res[] = $re;
                }
                $any = self::some($res, static fn (string $re) => preg_match($re, $t) === 1);
                return $op === 'regex' ? $any : !$any;
            case 'numEquals':
            case 'numNotEquals':
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $a = Values::numberOf($raw);
                $b = Values::numberOf($values[0] ?? null);
                if ($a === null || $b === null) {
                    return false;
                }
                return match ($op) {
                    'numEquals' => $a === $b,
                    'numNotEquals' => $a !== $b,
                    'gt' => $a > $b,
                    'gte' => $a >= $b,
                    'lt' => $a < $b,
                    default => $a <= $b,
                };
            case 'semverEquals':
            case 'semverNotEquals':
            case 'semverGt':
            case 'semverGte':
            case 'semverLt':
            case 'semverLte':
            case 'semverIn':
            case 'semverNotIn':
                $a = Values::parseSemver($raw);
                if ($a === null) {
                    return false;
                }
                $bs = array_map([Values::class, 'parseSemver'], $values);
                if (in_array(null, $bs, true)) {
                    return false;
                }
                if ($op === 'semverIn') {
                    return self::some($bs, static fn (array $b) => Values::compareSemver($a, $b) === 0);
                }
                if ($op === 'semverNotIn') {
                    return !self::some($bs, static fn (array $b) => Values::compareSemver($a, $b) === 0);
                }
                if (!isset($bs[0])) {
                    return false;
                }
                $d = Values::compareSemver($a, $bs[0]);
                return match ($op) {
                    'semverEquals' => $d === 0,
                    'semverNotEquals' => $d !== 0,
                    'semverGt' => $d > 0,
                    'semverGte' => $d >= 0,
                    'semverLt' => $d < 0,
                    default => $d <= 0,
                };
            case 'before':
            case 'after':
                $a = Values::instantOf($raw);
                $b = Values::instantOf($values[0] ?? null);
                if ($a === null || $b === null) {
                    return false;
                }
                return $op === 'before' ? $a < $b : $a > $b;
            case 'arrayContainsAny':
            case 'arrayNotContainsAny':
            case 'arrayContainsAll':
                $arr = Values::arrayOf($raw);
                if ($arr === null) {
                    return false;
                }
                $elems = self::textValues($arr, $ci);
                $vs = self::textValues($values, $ci);
                if ($op === 'arrayContainsAll') {
                    return !self::some($vs, static fn (string $v) => !in_array($v, $elems, true));
                }
                $any = self::some($vs, static fn (string $v) => in_array($v, $elems, true));
                return $op === 'arrayContainsAny' ? $any : !$any;
            case 'equalsHashed':
            case 'notEqualsHashed':
            case 'inHashed':
            case 'notInHashed':
            case 'startsWithHashed':
            case 'notStartsWithHashed':
            case 'endsWithHashed':
            case 'notEndsWithHashed':
                $t = Values::textOf($raw);
                if ($t === null) {
                    return false;
                }
                $salt = $this->salt();
                if (in_array($op, ['startsWithHashed', 'notStartsWithHashed', 'endsWithHashed', 'notEndsWithHashed'], true)) {
                    $any = self::affixMatches($salt, $t, $values, $op === 'endsWithHashed' || $op === 'notEndsWithHashed');
                    return str_starts_with($op, 'not') ? !$any : $any;
                }
                $h = Hash::confidential($salt, $t);
                $first = $values[0] ?? null;
                return match ($op) {
                    'equalsHashed' => is_string($first) && $h === $first,
                    'notEqualsHashed' => is_string($first) && $h !== $first,
                    'inHashed' => in_array($h, $values, true),
                    default => !in_array($h, $values, true),
                };
            case 'arrayContainsAnyHashed':
            case 'arrayNotContainsAnyHashed':
                $arr = Values::arrayOf($raw);
                if ($arr === null) {
                    return false;
                }
                $salt = $this->salt();
                $any = self::some($arr, static function (mixed $e) use ($salt, $values): bool {
                    $t = Values::textOf($e);
                    return $t !== null && in_array(Hash::confidential($salt, $t), $values, true);
                });
                return $op === 'arrayContainsAnyHashed' ? $any : !$any;
            default:
                return false;
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function enter(string $frame, callable $fn): mixed
    {
        if (in_array($frame, $this->stack, true)) {
            throw new CycleException('Cycle detected: ' . implode(' → ', [...$this->stack, $frame]));
        }
        if (count($this->stack) >= self::MAX_DEPTH) {
            throw new CycleException('Flag references nest deeper than ' . self::MAX_DEPTH . ' levels: ' . implode(' → ', $this->stack));
        }
        $this->stack[] = $frame;
        try {
            return $fn();
        } finally {
            array_pop($this->stack);
        }
    }

    private function segmentMatches(mixed $key): ?bool
    {
        $key = Values::textOf($key);
        $segments = Json::asMap($this->payload['segments'] ?? null);
        $seg = $key !== null && array_key_exists($key, $segments) ? $segments[$key] : null;
        if (!Values::truthy($seg)) {
            return null;
        }
        $seg = Json::asMap($seg);
        return $this->enter("segment:$key", function () use ($seg): bool {
            $kind = $seg['kind'] ?? null;
            if ($kind === 'list') {
                $attr = $seg['attribute'] ?? null;
                $t = is_string($attr) ? Values::textOf(self::lookupAttribute($this->attributes, $attr)) : null;
                if ($t === null) {
                    return false;
                }
                $needle = Values::truthy($seg['hashed'] ?? null) ? Hash::confidential($this->salt(), $t) : $t;
                return Json::isList($seg['values'] ?? null) && in_array($needle, $seg['values'], true);
            }
            if ($kind === 'conditions') {
                return $this->allMatch($seg['conditions'] ?? null);
            }
            return false;
        });
    }

    private static function compareFlag(mixed $op, mixed $actual, mixed $expected): bool
    {
        return match ($op) {
            'isOn' => Values::isOn($actual),
            'isOff' => !Values::isOn($actual),
            'equals' => Values::jsonEquals($actual, $expected),
            'notEquals' => !Values::jsonEquals($actual, $expected),
            default => false,
        };
    }

    /**
     * Value of a referenced flag, or null when it does not exist.
     *
     * @return array{value: mixed}|null
     */
    private function referencedValue(mixed $key): ?array
    {
        $key = Values::textOf($key);
        if ($key === null) {
            return null;
        }
        $res = $this->evalFlag($key);
        return $res->reason === Reason::FLAG_NOT_FOUND ? null : ['value' => $res->value];
    }

    private function conditionMatches(mixed $c): bool
    {
        if (!Json::isObject($c)) {
            return false;
        }
        $c = Json::asMap($c);
        switch ($c['kind'] ?? null) {
            case 'attribute':
                return $this->evalAttribute($c);
            case 'segment':
                $m = $this->segmentMatches($c['segment'] ?? null);
                if ($m === null) {
                    return false;
                }
                $op = $c['operator'] ?? null;
                if ($op === 'in') {
                    return $m;
                }
                if ($op === 'notIn') {
                    return !$m;
                }
                return false;
            case 'flag':
                $ref = $this->referencedValue($c['flag'] ?? null);
                return $ref !== null && self::compareFlag($c['operator'] ?? null, $ref['value'], $c['value'] ?? null);
            case 'group':
                $list = Json::asList($c['conditions'] ?? null);
                $op = $c['op'] ?? null;
                if ($op === 'and') {
                    $r = $this->allMatch($list);
                } elseif ($op === 'or') {
                    $r = self::some($list, fn (mixed $x) => $this->conditionMatches($x));
                } else {
                    return false;
                }
                return Values::truthy($c['negate'] ?? null) ? !$r : $r;
            default:
                return false;
        }
    }

    private function allMatch(mixed $conditions): bool
    {
        if (!Values::truthy($conditions)) {
            return true;
        }
        foreach (self::each($conditions) as $c) {
            if (!$this->conditionMatches($c)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<array-key, mixed> $flag */
    private static function base(string $key, array $flag, string $field, string $reason): EvaluationResult
    {
        return new EvaluationResult(self::flagKey($flag, $key), $flag[$field] ?? null, $reason);
    }

    /** @param array<array-key, mixed> $flag */
    private static function flagKey(array $flag, string $key): string
    {
        return Values::textOf($flag['key'] ?? null) ?? $key;
    }

    /** @param array<array-key, mixed> $flag @param array<array-key, mixed> $rule */
    private static function served(string $key, array $flag, array $rule, mixed $value, mixed $variationId): EvaluationResult
    {
        return new EvaluationResult(
            self::flagKey($flag, $key),
            $value,
            Reason::RULE_MATCH,
            ruleId: Values::textOf($rule['id'] ?? null),
            variationId: Values::truthy($variationId) ? Values::textOf($variationId) : null,
        );
    }

    private static function stickyDocKey(string $attribute, string $value): string
    {
        return "$attribute||$value";
    }

    /**
     * @param array<array-key, mixed> $flag
     * @param array<array-key, mixed> $rule
     */
    private function runExperiment(string $key, array $flag, array $rule): ?EvaluationResult
    {
        $variations = array_map([Json::class, 'asMap'], Json::asList($rule['variations'] ?? null));
        if ($variations === []) {
            return null;
        }
        $ruleHashAttribute = is_string($rule['hashAttribute'] ?? null) ? $rule['hashAttribute'] : '';
        $fallbackAttribute = Values::truthy($rule['fallbackAttribute'] ?? null) && is_string($rule['fallbackAttribute']) ? $rule['fallbackAttribute'] : null;
        $hashAttribute = $ruleHashAttribute;
        $hv = $this->hashValueOf($rule['hashAttribute'] ?? null);
        $fallbackValue = $fallbackAttribute !== null ? $this->hashValueOf($fallbackAttribute) : null;
        if ($hv === null && $fallbackAttribute !== null && $fallbackValue !== null) {
            $hashAttribute = $fallbackAttribute;
            $hv = $fallbackValue;
        }
        if ($hv === null) {
            return null;
        }

        $experimentKey = Values::textOf($rule['key'] ?? null) ?? '';
        $bucketVersion = Values::textOf($rule['bucketVersion'] ?? null) ?? '0';
        $useSticky = $this->sticky !== null && !Values::truthy($rule['disableStickyBucketing'] ?? null);
        $index = -1;
        $bucket = Hash::bucket(Values::textOf($rule['seed'] ?? null) ?? '', $hv);
        $stickyUsed = false;
        if ($useSticky) {
            $merged = [];
            if ($fallbackAttribute !== null && $fallbackValue !== null) {
                $merged = array_replace($merged, $this->sticky->getAssignments(self::stickyDocKey($fallbackAttribute, $fallbackValue)) ?? []);
            }
            $primary = $this->hashValueOf($rule['hashAttribute'] ?? null);
            if ($primary !== null) {
                $merged = array_replace($merged, $this->sticky->getAssignments(self::stickyDocKey($ruleHashAttribute, $primary)) ?? []);
            }
            $prefix = $experimentKey . '__';
            $minVersion = Values::jsNumber($rule['minBucketVersion'] ?? 0);
            foreach (array_keys($merged) as $k) {
                $k = (string) $k;
                if (!str_starts_with($k, $prefix)) {
                    continue;
                }
                $v = Values::jsNumber(substr($k, strlen($prefix)));
                if (Values::isInteger($v) && $v < $minVersion) {
                    return null; // blocked from re-entering
                }
            }
            $assigned = $merged[$prefix . $bucketVersion] ?? null;
            if ($assigned !== null) {
                foreach ($variations as $i => $variation) {
                    if (($variation['id'] ?? null) === $assigned) {
                        $index = $i;
                        $stickyUsed = true;
                        break;
                    }
                }
            }
        }
        if ($index < 0) {
            if (Values::truthy($rule['namespace'] ?? null)) {
                $ns = Json::asMap($rule['namespace']);
                $n = Hash::bucket('__' . (Values::textOf($ns['id'] ?? null) ?? ''), $hv);
                $start = array_key_exists('start', $ns) ? Values::jsNumber($ns['start']) : NAN;
                $end = array_key_exists('end', $ns) ? Values::jsNumber($ns['end']) : NAN;
                if (!($n >= $start && $n < $end)) {
                    return null;
                }
            }
            $coverage = self::clamp01($rule['coverage'] ?? null);
            $cum = 0.0;
            foreach ($variations as $i => $variation) {
                $w = Values::jsNumber($variation['weight'] ?? null);
                $w = is_nan($w) ? 0.0 : max(0.0, $w);
                $start = $cum;
                $end = $cum + $coverage * $w;
                $cum += $w;
                if ($bucket >= $start && $bucket < $end) {
                    $index = $i;
                    break;
                }
            }
            if ($index < 0) {
                return null;
            }
            if ($useSticky) {
                $docKey = self::stickyDocKey($hashAttribute, $hv);
                $doc = $this->sticky->getAssignments($docKey) ?? [];
                $doc[$experimentKey . '__' . $bucketVersion] = Values::textOf($variations[$index]['id'] ?? null) ?? '';
                $this->sticky->saveAssignments($docKey, $doc);
            }
        }
        $variation = $variations[$index];
        $variationId = Values::textOf($variation['id'] ?? null) ?? '';
        $experiment = ['key' => $experimentKey];
        if (array_key_exists('name', $rule) && $rule['name'] !== null) {
            $experiment['name'] = $rule['name'];
        }
        $experiment['variationId'] = $variationId;
        if (array_key_exists('name', $variation) && $variation['name'] !== null) {
            $experiment['variationName'] = $variation['name'];
        }
        $experiment += [
            'variationIndex' => $index,
            'hashAttribute' => $hashAttribute,
            'hashValue' => $hv,
            'bucket' => $bucket,
            'stickyBucketUsed' => $stickyUsed,
        ];
        return new EvaluationResult(
            self::flagKey($flag, $key),
            $variation['value'] ?? null,
            Reason::EXPERIMENT,
            ruleId: Values::textOf($rule['id'] ?? null),
            variationId: $variationId,
            inExperiment: true,
            experiment: $experiment,
        );
    }

    /**
     * @param array<array-key, mixed> $flag
     * @param array<array-key, mixed> $rule
     */
    private function applyRule(string $key, array $flag, array $rule): ?EvaluationResult
    {
        switch ($rule['type'] ?? null) {
            case 'force':
                return self::served($key, $flag, $rule, $rule['value'] ?? null, $rule['variationId'] ?? null);
            case 'rollout':
                $hv = $this->hashValueOf($rule['hashAttribute'] ?? null);
                if ($hv === null) {
                    return null;
                }
                if (Hash::bucket(Values::textOf($rule['seed'] ?? null) ?? '', $hv) >= self::effectiveCoverage($rule, $this->now)) {
                    return null;
                }
                return self::served($key, $flag, $rule, $rule['value'] ?? null, $rule['variationId'] ?? null);
            case 'split':
                $hv = $this->hashValueOf($rule['hashAttribute'] ?? null);
                if ($hv === null || !Json::isList($rule['buckets'] ?? null)) {
                    return null;
                }
                $n = Hash::bucket(Values::textOf($rule['seed'] ?? null) ?? '', $hv);
                $cum = 0.0;
                foreach ($rule['buckets'] as $b) {
                    $b = Json::asMap($b);
                    $w = Values::jsNumber($b['weight'] ?? null);
                    $cum += is_nan($w) ? 0.0 : max(0.0, $w);
                    if ($n < $cum) {
                        return self::served($key, $flag, $rule, $b['value'] ?? null, $b['variationId'] ?? null);
                    }
                }
                return null;
            case 'experiment':
                return $this->runExperiment($key, $flag, $rule);
            default:
                return null;
        }
    }

    private function evalFlag(string $key): EvaluationResult
    {
        $flags = Json::asMap($this->payload['flags'] ?? null);
        $flag = array_key_exists($key, $flags) ? $flags[$key] : null;
        if (!Values::truthy($flag)) {
            return new EvaluationResult($key, null, Reason::FLAG_NOT_FOUND);
        }
        $flag = Json::asMap($flag);
        return $this->enter("flag:$key", function () use ($key, $flag): EvaluationResult {
            if (!Values::truthy($flag['enabled'] ?? null)) {
                return self::base($key, $flag, 'offValue', Reason::DISABLED);
            }
            foreach (self::each($flag['prerequisites'] ?? null) as $p) {
                $p = Json::asMap($p);
                $ref = $this->referencedValue($p['flag'] ?? null);
                if ($ref === null || !self::compareFlag($p['operator'] ?? null, $ref['value'], $p['value'] ?? null)) {
                    return self::base($key, $flag, 'offValue', Reason::PREREQUISITE_FAILED);
                }
            }
            foreach (self::each($this->payload['holdouts'] ?? null) as $h) {
                $h = Json::asMap($h);
                if (!Json::isList($h['flags'] ?? null) || !in_array($key, $h['flags'], true)) {
                    continue;
                }
                $hv = $this->hashValueOf($h['hashAttribute'] ?? null);
                if ($hv !== null && Hash::bucket(Values::textOf($h['seed'] ?? null) ?? '', $hv) < self::clamp01($h['coverage'] ?? null)) {
                    return self::base($key, $flag, 'defaultValue', Reason::HOLDOUT);
                }
            }
            foreach (self::each($flag['rules'] ?? null) as $rule) {
                if (!Values::truthy($rule) || !self::isScheduleActive(Json::asMap($rule)['schedule'] ?? null, $this->now)) {
                    continue;
                }
                $rule = Json::asMap($rule);
                if (!$this->allMatch($rule['conditions'] ?? null)) {
                    continue;
                }
                $res = $this->applyRule($key, $flag, $rule);
                if ($res !== null) {
                    return $res;
                }
            }
            return self::base($key, $flag, 'defaultValue', Reason::DEFAULT);
        });
    }

    /**
     * Elements of a list field (`null` = empty). Anything else is a malformed payload, which the
     * reference implementation also rejects (it cannot iterate it), so the evaluation errors.
     *
     * @return list<mixed>
     */
    private static function each(mixed $v): array
    {
        if ($v === null) {
            return [];
        }
        if (!Json::isList($v)) {
            throw new \UnexpectedValueException('Malformed flags payload: expected a list, got ' . (Values::jsonTypeOf($v) ?? 'null'));
        }
        return $v;
    }

    /**
     * @param array<array-key, mixed> $items
     * @param callable(mixed): bool $pred
     */
    private static function some(array $items, callable $pred): bool
    {
        foreach ($items as $item) {
            if ($pred($item)) {
                return true;
            }
        }
        return false;
    }
}
