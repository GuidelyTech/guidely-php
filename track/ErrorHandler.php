<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Opt-in handlers that report what your code did not catch:
 *
 * * uncaught exceptions (`set_exception_handler`) as crashes — `level: fatal`, `handled: false`,
 *   mechanism `uncaught` — flushed at once, then passed to the previous handler (or rethrown, so
 *   PHP still logs it and exits with status 255);
 * * PHP warnings, notices and deprecations (`set_error_handler`) that `error_reporting()` lets
 *   through: as events for the levels in `$eventLevels` (warnings and user errors by default),
 *   as breadcrumbs for the rest; the previous handler (or PHP's own) runs as before;
 * * fatal errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`: out of memory, time
 *   limit, parse errors in included files) from `error_get_last()` in a shutdown function, as
 *   crashes with mechanism `fatal_error`, flushed at once.
 *
 * ```php
 * $guidely = new GuidelyTrack(getenv('GUIDELY_SECRET_KEY'), environment: 'production');
 * ErrorHandler::register($guidely);
 * ```
 *
 * In a web request, events carry the request from `$_SERVER` ({@see RequestContext::fromGlobals()}:
 * method, URL, headers; cookies and credentials removed).
 */
final class ErrorHandler
{
    /** Error levels that end the script; reported from the shutdown function. */
    public const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    /** The default `$eventLevels`: warnings and user errors become events, notices and deprecations breadcrumbs. */
    public const DEFAULT_EVENT_LEVELS = E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR;
    /** Memory kept free so an out-of-memory fatal error can still be reported. */
    private const RESERVED_MEMORY_BYTES = 32768;

    /** @var callable|null */
    private $previousExceptionHandler = null;
    /** @var callable|null */
    private $previousErrorHandler = null;
    /** @var \Closure(): ?array */
    private readonly \Closure $lastError;
    private bool $capturing = false;
    private bool $reportedUncaught = false;
    private ?string $reservedMemory = null;
    private bool $registered = false;

    /**
     * @param int $eventLevels PHP error levels reported as events; the other levels `error_reporting()` allows become breadcrumbs
     * @param callable(): ?array|null $lastError @internal `error_get_last()` (tests)
     */
    public function __construct(
        private readonly GuidelyTrack $client,
        private readonly int $eventLevels = self::DEFAULT_EVENT_LEVELS,
        ?callable $lastError = null,
    ) {
        $this->lastError = $lastError === null ? static fn (): ?array => error_get_last() : \Closure::fromCallable($lastError);
    }

    /**
     * Installs the exception, error and shutdown handlers (each chains the one installed before).
     *
     * @param bool $exceptions report uncaught exceptions
     * @param bool $errors report PHP warnings / notices (as events or breadcrumbs)
     * @param bool $fatalErrors report fatal errors at shutdown
     */
    public static function register(
        GuidelyTrack $client,
        int $eventLevels = self::DEFAULT_EVENT_LEVELS,
        bool $exceptions = true,
        bool $errors = true,
        bool $fatalErrors = true,
    ): self {
        $handler = new self($client, $eventLevels);
        $handler->install($exceptions, $errors, $fatalErrors);
        return $handler;
    }

    /** @internal see register() */
    public function install(bool $exceptions = true, bool $errors = true, bool $fatalErrors = true): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        if ($exceptions) {
            $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        }
        if ($errors) {
            $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        }
        if ($fatalErrors) {
            $this->reservedMemory = str_repeat('x', self::RESERVED_MEMORY_BYTES);
            register_shutdown_function([$this, 'handleShutdown']);
        }
    }

    /** The exception handler that was installed before this one (null: PHP's own). */
    public function previousExceptionHandler(): ?callable
    {
        return $this->previousExceptionHandler;
    }

    /** The error handler that was installed before this one (null: PHP's own). */
    public function previousErrorHandler(): ?callable
    {
        return $this->previousErrorHandler;
    }

    /** @internal for tests: the handlers to chain to */
    public function chainTo(?callable $exceptionHandler, ?callable $errorHandler): void
    {
        $this->previousExceptionHandler = $exceptionHandler;
        $this->previousErrorHandler = $errorHandler;
    }

    /**
     * `set_exception_handler` callback: reports the crash, flushes, then delegates (or rethrows).
     *
     * @throws \Throwable the exception itself when no handler was installed before
     */
    public function handleException(\Throwable $exception): void
    {
        $this->reportedUncaught = true;
        $this->guarded(function () use ($exception): void {
            $this->client->capture($exception, null, null, RequestContext::fromGlobals(), ['source' => 'uncaught', 'mechanism' => 'uncaught', 'handled' => false, 'level' => Level::Fatal]);
            $this->client->flush();
        });
        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);
            return;
        }
        // No handler before ours: let PHP report it as usual ("PHP Fatal error: Uncaught …", exit 255).
        // Rethrowing from an exception handler is how PHP's own handling is restored.
        set_exception_handler(null);
        throw $exception;
    }

    /**
     * `set_error_handler` callback. Returns what the previous handler returns, or false so PHP's
     * standard handler still logs / displays the error.
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $level) !== 0) {
            $this->guarded(function () use ($level, $message, $file, $line): void {
                $label = self::levelName($level);
                if (($this->eventLevels & $level) !== 0) {
                    $frames = StackTrace::forError($file, $line);
                    $this->client->capture(null, 'ErrorException', "$label: $message", RequestContext::fromGlobals(), [
                        'source' => 'error_handler', 'mechanism' => 'error_handler', 'handled' => false, 'level' => self::severity($level),
                    ], $frames);
                } else {
                    $this->client->addBreadcrumb([
                        'type' => 'error',
                        'category' => 'error_reporting',
                        'message' => "$label: $message",
                        'level' => self::severity($level),
                        'data' => ['file' => $file, 'line' => $line],
                    ]);
                }
            });
        }
        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($level, $message, $file, $line);
        }
        return false;
    }

    /** Shutdown function: reports a fatal error from `error_get_last()` and flushes at once. */
    public function handleShutdown(): void
    {
        $this->reservedMemory = null;
        $error = ($this->lastError)();
        if (!is_array($error) || !is_int($error['type'] ?? null) || ($error['type'] & self::FATAL_ERRORS) === 0) {
            return;
        }
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        // An uncaught exception we already reported ends the script with "Uncaught …": not a second crash.
        if ($this->reportedUncaught && str_starts_with($message, 'Uncaught ')) {
            return;
        }
        if (str_contains($message, 'Allowed memory size')) {
            self::raiseMemoryLimit();
        }
        $this->guarded(function () use ($error, $message): void {
            $file = is_string($error['file'] ?? null) ? $error['file'] : null;
            $line = is_int($error['line'] ?? null) ? $error['line'] : null;
            $this->client->capture(null, self::fatalType($error['type']), $message, RequestContext::fromGlobals(), [
                'source' => 'fatal_error', 'mechanism' => 'fatal_error', 'handled' => false, 'level' => Level::Fatal,
            ], [['file' => $file, 'line' => $line, 'function' => null]]);
            $this->client->flush();
        });
    }

    /** Runs $fn once at a time (an error inside the SDK must not recurse) and swallows what it throws. */
    private function guarded(\Closure $fn): void
    {
        if ($this->capturing) {
            return;
        }
        $this->capturing = true;
        try {
            $fn();
        } catch (\Throwable) {
            // reporting must never get in the way of the app's own handling
        } finally {
            $this->capturing = false;
        }
    }

    private static function severity(int $level): Level
    {
        return match (true) {
            ($level & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING)) !== 0 => Level::Warning,
            ($level & (E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED | 2048)) !== 0 => Level::Info, // 2048: E_STRICT (deprecated constant)
            default => Level::Error,
        };
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            2048 => 'Strict Standards',
            E_RECOVERABLE_ERROR => 'Recoverable fatal error',
            E_USER_ERROR => 'Error',
            default => 'Error',
        };
    }

    private static function fatalType(int $level): string
    {
        return match ($level) {
            E_PARSE => 'ParseError',
            E_COMPILE_ERROR => 'CompileError',
            E_CORE_ERROR => 'CoreError',
            default => 'FatalError',
        };
    }

    /** A little headroom (5 MB) so reporting an out-of-memory error does not fail the same way. */
    private static function raiseMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return;
        }
        $bytes = (int) $limit;
        $unit = strtolower(substr(trim($limit), -1));
        $bytes *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        @ini_set('memory_limit', (string) ($bytes + 5 * 1024 * 1024));
    }
}
