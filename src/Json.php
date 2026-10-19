<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * JSON model used throughout the SDK.
 *
 * PHP has no separate type for JSON objects, so the SDK uses one rule everywhere:
 *
 *  - a PHP array is a JSON **array** when `array_is_list()` is true (so `[]` is an empty array);
 *  - any other array, and any `stdClass`, is a JSON **object**.
 *
 * {@see Json::decode()} produces values in this model: ordinary objects become associative arrays
 * (what PHP developers expect), and only objects that would be ambiguous as arrays — `{}` and
 * objects whose keys are `"0"`, `"1"`, … — are kept as `stdClass`. Pass `new \stdClass()` (or
 * `(object) []`) as an attribute when you mean an empty JSON object.
 */
final class Json
{
    /**
     * Decode JSON text into the SDK's JSON model.
     *
     * @throws \JsonException on invalid JSON
     */
    public static function decode(string $json): mixed
    {
        return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** Convert a value decoded with `json_decode($s, false)` into the SDK's JSON model. */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $map = [];
            foreach (get_object_vars($value) as $k => $v) {
                $map[$k] = self::normalize($v);
            }
            if ($map === [] || array_is_list($map)) {
                return (object) $map;
            }
            return $map;
        }
        if (is_array($value)) {
            return array_map([self::class, 'normalize'], $value);
        }
        return $value;
    }

    /** JSON text of a value (`[]` attribute sets are sent as `{}` by the caller where needed). */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** True for a JSON array (a list). */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** True for a JSON object: a non-list array or any object. */
    public static function isObject(mixed $value): bool
    {
        return is_object($value) || (is_array($value) && !array_is_list($value));
    }

    /**
     * Members of an object as an array (arrays as-is, `stdClass` via its properties); anything
     * else is treated as an empty object. Used to walk payload structures defensively.
     *
     * @return array<array-key, mixed>
     */
    public static function asMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        return [];
    }

    /**
     * Elements of a JSON array, or `[]` when the value is not one.
     *
     * @return list<mixed>
     */
    public static function asList(mixed $value): array
    {
        return self::isList($value) ? $value : [];
    }
}
