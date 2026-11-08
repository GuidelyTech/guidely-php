<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Frames from PHP traces. A trace entry's `file`/`line` is where its function was *called*, which
 * is inside the function of the next entry; the throw site (`getFile()`/`getLine()`) is inside the
 * function of the first entry. So, as Sentry PHP does, each location is paired with the function of
 * the entry after it, and the outermost location runs in `{main}`.
 *
 * Frames here are `['file' => ?string, 'line' => ?int, 'function' => ?string]`, innermost first.
 * Arguments are never read.
 *
 * @internal
 */
final class StackTrace
{
    /**
     * @param list<array<string, mixed>> $trace `getTrace()` / `debug_backtrace()` entries
     * @param bool $atFirstCall the error happened at `$trace[0]`'s call (a warning raised inside an
     *     internal function), so there is no separate throw site
     * @return list<array{file: ?string, line: ?int, function: ?string}>
     */
    public static function fromTrace(array $trace, ?string $file, ?int $line, bool $atFirstCall = false): array
    {
        $trace = array_values($trace);
        $frames = [];
        if (!$atFirstCall) {
            $frames[] = ['file' => $file, 'line' => $line, 'function' => self::functionOf($trace[0] ?? null)];
        }
        $n = count($trace);
        for ($i = 0; $i < $n; $i++) {
            $entry = $trace[$i];
            $frames[] = [
                'file' => isset($entry['file']) && is_string($entry['file']) ? $entry['file'] : null,
                'line' => isset($entry['line']) && is_int($entry['line']) ? $entry['line'] : null,
                'function' => $i + 1 < $n ? self::functionOf($trace[$i + 1]) : null,
            ];
        }
        return $frames;
    }

    /** @return list<array{file: ?string, line: ?int, function: ?string}> */
    public static function fromThrowable(\Throwable $e): array
    {
        return self::fromTrace($e->getTrace(), $e->getFile(), $e->getLine());
    }

    /**
     * The current stack for an error reported to a `set_error_handler` callback, without the
     * handler's own frames.
     *
     * @return list<array{file: ?string, line: ?int, function: ?string}>
     */
    public static function forError(string $file, int $line, ?array $backtrace = null): array
    {
        $trace = $backtrace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // Drop the SDK's frames (this method, the handler) from the top.
        while ($trace !== [] && isset($trace[0]['class']) && str_starts_with((string) $trace[0]['class'], 'GuidelyTech\\Track\\')) {
            array_shift($trace);
        }
        $first = $trace[0] ?? null;
        $atFirstCall = $first !== null && ($first['file'] ?? null) === $file && ($first['line'] ?? null) === $line;
        return self::fromTrace($trace, $file, $line, $atFirstCall);
    }

    /** `Class::method`, `function` or `{closure}` as PHP names it; null for none. */
    public static function functionOf(?array $entry): ?string
    {
        if ($entry === null || !isset($entry['function']) || !is_string($entry['function'])) {
            return null;
        }
        $class = isset($entry['class']) && is_string($entry['class']) ? self::className($entry['class']) : null;
        return $class !== null ? $class . '::' . $entry['function'] : $entry['function'];
    }

    /** A class name; anonymous classes lose the NUL-separated path PHP appends (`class@anonymous`). */
    public static function className(string $class): string
    {
        $nul = strpos($class, "\0");
        return $nul === false ? $class : substr($class, 0, $nul);
    }
}
