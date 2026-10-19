<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * Value conversions used by the condition operators (docs/feature-flags-spec.md §3.2–3.3).
 * Mirrors packages/flags/src/values.ts. JSON values follow the model described in {@see Json}.
 */
final class Values
{
    private const NUMERIC = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D';
    private const SEMVER = '/^[vV]?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const ISO = '/^(\d{4})-(\d{2})-(\d{2})(?:[Tt ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(Z|z|[+-]\d{2}:\d{2})?)?$/D';

    /** Text form of a scalar, or `null` for arrays, objects, null and non-finite numbers. */
    public static function textOf(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? self::formatNumber($v) : null;
        }
        return null;
    }

    /**
     * ECMAScript `Number.prototype.toString()` for a finite float: integral values without a
     * decimal point (`3.0` → `"3"`) below 1e21, otherwise the shortest round-trip digits.
     */
    public static function formatNumber(float $f): string
    {
        if ($f == 0.0) {
            return '0';
        }
        $neg = $f < 0;
        $a = abs($f);
        $s = '';
        for ($p = 0; $p <= 16; $p++) {
            $s = sprintf('%.' . $p . 'e', $a);
            if ((float) $s === $a) {
                break;
            }
        }
        if (!preg_match('/^(\d)(?:\.(\d+))?e([+-]?\d+)$/', $s, $m)) {
            return ($neg ? '-' : '') . $s; // unreachable for finite floats
        }
        $digits = rtrim($m[1] . ($m[2] ?? ''), '0');
        if ($digits === '') {
            $digits = '0';
        }
        $k = strlen($digits);
        $n = (int) $m[3] + 1;
        if ($k <= $n && $n <= 21) {
            $out = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $out = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $out = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $out = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e < 0 ? '-' : '+') . abs($e);
        }
        return ($neg ? '-' : '') . $out;
    }

    /** Number for the numeric operators: numbers, or strings holding a decimal number. */
    public static function numberOf(mixed $v): ?float
    {
        if (is_int($v)) {
            return (float) $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if (is_string($v)) {
            $s = self::trim($v);
            if (!preg_match(self::NUMERIC, $s)) {
                return null;
            }
            $n = (float) $s;
            return is_finite($n) ? $n : null;
        }
        return null;
    }

    /**
     * Elements of an array attribute (or a string holding a JSON array).
     *
     * @return list<mixed>|null
     */
    public static function arrayOf(mixed $v): ?array
    {
        if (Json::isList($v)) {
            return $v;
        }
        if (is_string($v)) {
            $s = self::trim($v);
            if (!str_starts_with($s, '[')) {
                return null;
            }
            try {
                $parsed = Json::decode($s);
            } catch (\JsonException) {
                return null;
            }
            return Json::isList($parsed) ? $parsed : null;
        }
        return null;
    }

    /**
     * @return array{major: float, minor: float, patch: float, pre: list<string>}|null
     */
    public static function parseSemver(mixed $v): ?array
    {
        if (is_int($v) || is_float($v)) {
            $s = self::textOf($v);
        } elseif (is_string($v)) {
            $s = self::trim($v);
        } else {
            return null;
        }
        if ($s === null || !preg_match(self::SEMVER, $s, $m)) {
            return null;
        }
        return [
            'major' => (float) $m[1],
            'minor' => (float) (($m[2] ?? '') === '' ? 0 : $m[2]),
            'patch' => (float) (($m[3] ?? '') === '' ? 0 : $m[3]),
            'pre' => ($m[4] ?? '') === '' ? [] : explode('.', $m[4]),
        ];
    }

    /**
     * SemVer 2.0 precedence: -1, 0 or 1.
     *
     * @param array{major: float, minor: float, patch: float, pre: list<string>} $a
     * @param array{major: float, minor: float, patch: float, pre: list<string>} $b
     */
    public static function compareSemver(array $a, array $b): int
    {
        $c = ($a['major'] <=> $b['major']) ?: ($a['minor'] <=> $b['minor']) ?: ($a['patch'] <=> $b['patch']);
        if ($c !== 0) {
            return $c;
        }
        $ap = $a['pre'];
        $bp = $b['pre'];
        // A release outranks its pre-releases.
        if ($ap === [] || $bp === []) {
            return $ap !== [] ? -1 : ($bp !== [] ? 1 : 0);
        }
        $len = max(count($ap), count($bp));
        for ($i = 0; $i < $len; $i++) {
            if (!isset($ap[$i])) {
                return -1;
            }
            if (!isset($bp[$i])) {
                return 1;
            }
            $x = $ap[$i];
            $y = $bp[$i];
            $xn = preg_match('/^\d+$/', $x) === 1;
            $yn = preg_match('/^\d+$/', $y) === 1;
            if ($xn && $yn) {
                $d = (float) $x <=> (float) $y;
                if ($d !== 0) {
                    return $d;
                }
            } elseif ($xn) {
                return -1;
            } elseif ($yn) {
                return 1;
            } elseif ($x !== $y) {
                return strcmp($x, $y) < 0 ? -1 : 1;
            }
        }
        return 0;
    }

    /**
     * Milliseconds since the epoch: numbers are Unix seconds, strings ISO 8601 (strict subset,
     * see spec §3.3), `DateTimeInterface` as-is. `null` when the value is not an instant.
     */
    public static function instantOf(mixed $v): ?float
    {
        if ($v instanceof \DateTimeInterface) {
            return (float) ($v->getTimestamp() * 1000 + intdiv((int) $v->format('u'), 1000));
        }
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? $v * 1000.0 : null;
        }
        if (!is_string($v) || !preg_match(self::ISO, self::trim($v), $m)) {
            return null;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        $frac = $m[7] ?? '';
        $tz = $m[8] ?? '';
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }
        // Like Date.UTC, out-of-range days roll over into the next month.
        $days = self::daysFromCivil($year, $month, 1) + $day - 1;
        $ms = (float) (($days * 86400 + $hour * 3600 + $minute * 60 + $second) * 1000);
        if ($frac !== '') {
            $ms += floor((float) ('0.' . $frac) * 1000);
        }
        if ($tz !== '' && $tz !== 'Z' && $tz !== 'z') {
            $sign = $tz[0] === '-' ? -1 : 1;
            $ms -= $sign * ((int) substr($tz, 1, 2) * 60 + (int) substr($tz, 4, 2)) * 60000;
        }
        return $ms;
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date (H. Hinnant's algorithm). */
    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** JSON deep equality; numbers compare numerically, object keys unordered. */
    public static function jsonEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_string($a) || is_bool($a) || is_string($b) || is_bool($b)) {
            return $a === $b;
        }
        if (Json::isList($a)) {
            if (!Json::isList($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $i => $x) {
                if (!self::jsonEquals($x, $b[$i])) {
                    return false;
                }
            }
            return true;
        }
        if (Json::isObject($a) && Json::isObject($b)) {
            $ma = Json::asMap($a);
            $mb = Json::asMap($b);
            if (count($ma) !== count($mb)) {
                return false;
            }
            foreach ($ma as $k => $x) {
                if (!array_key_exists($k, $mb) || !self::jsonEquals($x, $mb[$k])) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** `isOn`: anything except null, false, 0 and "". */
    public static function isOn(mixed $v): bool
    {
        return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '');
    }

    /** JSON type name: string, number, boolean, array or object (`null` for null). */
    public static function jsonTypeOf(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => 'boolean',
            is_int($v), is_float($v) => 'number',
            is_string($v) => 'string',
            Json::isList($v) => 'array',
            is_array($v), is_object($v) => 'object',
            default => null,
        };
    }

    /** ECMAScript truthiness, for payload fields the reference implementation tests with `!x`. */
    public static function truthy(mixed $v): bool
    {
        if (is_float($v) && is_nan($v)) {
            return false;
        }
        return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '');
    }

    /** ECMAScript `Number(x)` for JSON values (NaN when not convertible). */
    public static function jsNumber(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if ($v === null) {
            return 0.0;
        }
        if (!is_string($v)) {
            return NAN;
        }
        $s = self::trim($v);
        if ($s === '') {
            return 0.0;
        }
        if (preg_match(self::NUMERIC, $s)) {
            return (float) $s;
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $s, $m)) {
            return (float) hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/', $s, $m)) {
            return (float) octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/', $s, $m)) {
            return (float) bindec($m[1]);
        }
        if (preg_match('/^([+-]?)Infinity$/', $s, $m)) {
            return $m[1] === '-' ? -INF : INF;
        }
        return NAN;
    }

    /** True for a finite number with no fractional part (`Number.isInteger`). */
    public static function isInteger(float $n): bool
    {
        return is_finite($n) && floor($n) === $n;
    }

    /** Unicode, locale-independent lowercasing. */
    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** ECMAScript `String.prototype.trim()`: strips Unicode white space and line terminators. */
    public static function trim(string $s): string
    {
        $t = preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $s);
        return $t ?? trim($s, " \t\n\r\0\x0B\f");
    }
}
