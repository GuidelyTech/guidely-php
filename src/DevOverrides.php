<?php

declare(strict_types=1);

namespace GuidelyTech\Flags;

/**
 * Dev overrides (docs/feature-flags-spec.md §4.1): flag values pinned from outside the code, so CI
 * and tests can switch flags without editing the app. Read once, when a client is created, from
 * the `GUIDELY_FLAG_OVERRIDES`, `GUIDELY_FLAG_OVERRIDES_FILE` and `GUIDELY_FLAG_OVERRIDE_BEHAVIOUR`
 * environment variables.
 *
 * @internal used by {@see GuidelyFlags}
 */
final class DevOverrides
{
    public const OVERRIDES_ENV = 'GUIDELY_FLAG_OVERRIDES';
    public const OVERRIDES_FILE_ENV = 'GUIDELY_FLAG_OVERRIDES_FILE';
    public const OVERRIDE_BEHAVIOUR_ENV = 'GUIDELY_FLAG_OVERRIDE_BEHAVIOUR';

    /** @var array<string, mixed> merged values: the file, then the environment variable (later wins per key) */
    public array $values = [];
    /** Replaces the configured `overrideBehaviour` when set. */
    public ?OverrideBehaviour $behaviour = null;
    /** @var list<string> where values or the behaviour came from, for the start-up warning */
    public array $sources = [];
    /** @var list<string> unreadable or malformed sources (ignored) */
    public array $errors = [];

    /**
     * Reads every source. Never throws: problems land in `errors`.
     *
     * @param (\Closure(string): ?string)|null $env variable lookup; default {@see self::env()}
     */
    public static function read(?\Closure $env = null): self
    {
        $env ??= self::env(...);
        $out = new self();
        $file = trim((string) $env(self::OVERRIDES_FILE_ENV));
        if ($file !== '') {
            $source = self::OVERRIDES_FILE_ENV . " ($file)";
            $text = is_dir($file) ? false : @file_get_contents($file);
            if ($text === false) {
                $reason = is_dir($file) ? 'it is a directory' : (error_get_last()['message'] ?? 'unreadable');
                $out->errors[] = "Could not read $source: $reason; ignoring it.";
            } else {
                $out->merge($text, $source);
            }
        }
        $out->merge($env(self::OVERRIDES_ENV), self::OVERRIDES_ENV);
        $behaviour = trim((string) $env(self::OVERRIDE_BEHAVIOUR_ENV));
        if ($behaviour !== '') {
            $out->behaviour = OverrideBehaviour::tryFrom($behaviour);
            if ($out->behaviour !== null) {
                $out->sources[] = self::OVERRIDE_BEHAVIOUR_ENV;
            } else {
                $out->errors[] = self::OVERRIDE_BEHAVIOUR_ENV . " must be localOnly, localOverRemote or remoteOverLocal (got \"$behaviour\"); ignoring it.";
            }
        }
        return $out;
    }

    /** An environment variable: `getenv()`, then `$_ENV`, then `$_SERVER` (null when unset). */
    public static function env(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }
        foreach ([$_ENV, $_SERVER] as $vars) {
            if (isset($vars[$name]) && is_string($vars[$name])) {
                return $vars[$name];
            }
        }
        return null;
    }

    private function merge(?string $text, string $source): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }
        $values = Overrides::parse($text);
        if ($values === null) {
            $this->errors[] = "$source is not a JSON object or a key:value list; ignoring it.";
            return;
        }
        if ($values === []) {
            return;
        }
        $this->values = array_replace($this->values, $values);
        $this->sources[] = $source;
    }
}
