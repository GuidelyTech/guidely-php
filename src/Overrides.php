<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * Overrides text (docs/feature-flags-spec.md §4.1), as held by `GUIDELY_FLAG_OVERRIDES` or an
 * overrides file: a JSON object (`{"new-checkout":true}`) or a list of `key:value` items separated
 * by commas or newlines (`new-checkout:true,limit:5,theme:"dark"`).
 */
final class Overrides
{
    /** Whitespace removed around keys and values (the same set as JavaScript's `String.prototype.trim`). */
    private const SPACE = '[\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /**
     * Flag key => value from overrides text. In a list, each value is JSON when it parses (strictly:
     * `01` and `True` stay text) and text otherwise; items without a `:` or with an empty key are
     * skipped and a later item wins over an earlier one. Values follow the SDK's JSON model
     * ({@see Json}). Null when the text starts with `{` but is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(string $text): ?array
    {
        $raw = self::trim($text);
        if (str_starts_with($raw, '{')) {
            try {
                $object = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!$object instanceof \stdClass) {
                return null;
            }
            $out = [];
            foreach (get_object_vars($object) as $key => $value) {
                $out[(string) $key] = Json::normalize($value);
            }
            return $out;
        }
        // Split on top-level commas and newlines: those inside quotes, arrays or objects belong to the value.
        $items = [];
        $depth = 0;
        $quoted = false;
        $start = 0;
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $c = $raw[$i];
            if ($quoted) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $quoted = false;
                }
            } elseif ($c === '"') {
                $quoted = true;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                $depth = max(0, $depth - 1);
            } elseif (($c === ',' || $c === "\n") && $depth === 0) {
                $items[] = substr($raw, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $items[] = substr($raw, $start);
        $out = [];
        foreach ($items as $item) {
            $sep = strpos($item, ':');
            if ($sep === false || $sep === 0) {
                continue;
            }
            $key = self::trim(substr($item, 0, $sep));
            if ($key === '') {
                continue;
            }
            $value = self::trim(substr($item, $sep + 1));
            try {
                $out[$key] = Json::decode($value);
            } catch (\JsonException) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private static function trim(string $text): string
    {
        $trimmed = preg_replace('/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $text);
        return $trimmed ?? trim($text); // not UTF-8: ASCII whitespace only
    }
}
