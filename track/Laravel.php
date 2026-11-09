<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * Wires a {@see GuidelyTrack} client into a Laravel 10+ app through Laravel's own events and hooks
 * (the SDK does not depend on Laravel; classes are referred to by name):
 *
 * - requests: an `http.server` transaction per request, named by route (`GET /users/{id}`), ended
 *   with the response status; under Octane, per request (`RequestReceived` / `RequestTerminated`);
 * - queries: every query (`QueryExecuted`) as a `db` span and a `query` breadcrumb;
 * - the HTTP client: `Http::` requests as `http.client` spans with trace headers (a global Guzzle middleware);
 * - queues: a `queue.process` transaction per job, continuing the trace of the code that dispatched it;
 * - errors: everything the exception handler reports (`reportable()`), with the request;
 * - flushing after the response is sent (`terminating`) and after each job.
 *
 * ```php
 * // app/Providers/AppServiceProvider.php
 * public function register(): void
 * {
 *     $this->app->singleton(GuidelyTrack::class, fn () => new GuidelyTrack(
 *         config('services.guidely.secret'),
 *         environment: config('services.guidely.environment', 'production'),
 *         tracing: new TracingOptions(tracesSampleRate: 0.2),
 *     ));
 * }
 *
 * public function boot(): void
 * {
 *     Laravel::instrument($this->app->make(GuidelyTrack::class), $this->app);
 * }
 * ```
 */
final class Laravel
{
    private const DB_QUERY = 'Illuminate\Database\Events\QueryExecuted';
    private const ROUTE_MATCHED = 'Illuminate\Routing\Events\RouteMatched';
    private const REQUEST_HANDLED = 'Illuminate\Foundation\Http\Events\RequestHandled';
    private const JOB_PROCESSING = 'Illuminate\Queue\Events\JobProcessing';
    private const JOB_PROCESSED = 'Illuminate\Queue\Events\JobProcessed';
    private const JOB_EXCEPTION = 'Illuminate\Queue\Events\JobExceptionOccurred';
    private const JOB_FAILED = 'Illuminate\Queue\Events\JobFailed';
    private const OCTANE_REQUEST_RECEIVED = 'Laravel\Octane\Events\RequestReceived';
    private const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    /**
     * @param object $app the application (`Illuminate\Foundation\Application`)
     * @param bool $requests request transactions
     * @param bool $queries query spans and breadcrumbs
     * @param bool $http `Http::` client spans and trace headers (Laravel 10.14+)
     * @param bool $queue job transactions and trace propagation to jobs
     * @param bool $errors report what the exception handler reports
     */
    public static function instrument(GuidelyTrack $guidely, object $app, bool $requests = true, bool $queries = true, bool $http = true, bool $queue = true, bool $errors = true): void
    {
        $events = $app->make('events');
        $listen = static function (string $event, \Closure $listener) use ($events): void {
            $events->listen($event, static function (object $e) use ($listener): void {
                try {
                    $listener($e);
                } catch (\Throwable) {
                    // monitoring must never break the app
                }
            });
        };

        if ($requests) {
            $octane = isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']);
            if (!$octane && method_exists($app, 'runningInConsole') && !$app->runningInConsole()) {
                $guidely->traceRequest($app->bound('request') ? self::request($app->make('request')) : null);
            }
            $listen(self::OCTANE_REQUEST_RECEIVED, static function (object $e) use ($guidely): void {
                $guidely->traceRequest(isset($e->request) && is_object($e->request) ? self::request($e->request) : null);
            });
            $listen(self::ROUTE_MATCHED, static function (object $e) use ($guidely): void {
                if (isset($e->route) && is_object($e->route) && method_exists($e->route, 'uri')) {
                    $guidely->setRoute((string) $e->route->uri());
                }
            });
            $listen(self::REQUEST_HANDLED, static function (object $e) use ($guidely): void {
                $guidely->finishRequest(self::status($e->response ?? null));
            });
            $listen(self::OCTANE_REQUEST_TERMINATED, static function (object $e) use ($guidely): void {
                $guidely->finishRequest(self::status($e->response ?? null));
                $guidely->flush();
            });
            if (method_exists($app, 'terminating')) {
                $app->terminating(static function () use ($guidely): void {
                    $guidely->finishRequest();
                    $guidely->flush();
                });
            }
        }

        if ($queries) {
            $listen(self::DB_QUERY, static function (object $e) use ($guidely): void {
                $connection = $e->connection ?? null;
                $guidely->recordQuery((string) ($e->sql ?? ''), (float) ($e->time ?? 0.0), [
                    'system' => is_object($connection) && method_exists($connection, 'getDriverName') ? (string) $connection->getDriverName() : null,
                    'database' => is_object($connection) && method_exists($connection, 'getDatabaseName') ? (string) $connection->getDatabaseName() : null,
                ]);
            });
        }

        if ($http && $app->bound('Illuminate\Http\Client\Factory')) {
            $factory = $app->make('Illuminate\Http\Client\Factory');
            if (method_exists($factory, 'globalMiddleware')) {
                $factory->globalMiddleware($guidely->guzzleMiddleware());
            }
        }

        if ($queue) {
            if (class_exists('Illuminate\Queue\Queue') && method_exists('Illuminate\Queue\Queue', 'createPayloadUsing')) {
                \Illuminate\Queue\Queue::createPayloadUsing(static function ($connection, $queueName, $payload) use ($guidely): array {
                    $headers = $guidely->getTraceHeaders();
                    return $headers === [] ? [] : ['guidely' => $headers];
                });
            }
            $job = null;
            $listen(self::JOB_PROCESSING, static function (object $e) use ($guidely, &$job): void {
                $j = $e->job ?? null;
                if (!is_object($j)) {
                    return;
                }
                $payload = method_exists($j, 'payload') ? $j->payload() : [];
                $guidely->continueTrace(is_array($payload['guidely'] ?? null) ? $payload['guidely'] : []);
                $job = $guidely->startTransaction([
                    'name' => method_exists($j, 'resolveName') ? (string) $j->resolveName() : 'job',
                    'op' => 'queue.process',
                    'attributes' => array_filter([
                        'messaging.system' => 'laravel',
                        'messaging.destination.name' => method_exists($j, 'getQueue') ? $j->getQueue() : null,
                        'messaging.message.id' => method_exists($j, 'getJobId') ? (string) $j->getJobId() : null,
                        'messaging.message.retry.count' => method_exists($j, 'attempts') ? max(0, (int) $j->attempts() - 1) : null,
                        'laravel.queue.connection' => is_string($e->connectionName ?? null) ? $e->connectionName : null,
                    ], static fn ($v) => $v !== null && $v !== ''),
                ]);
            });
            $done = static function (bool $failed) use ($guidely, &$job): void {
                if ($job instanceof Span) {
                    if ($failed) {
                        $job->setStatus('internal_error');
                    }
                    $job->end();
                    $job = null;
                }
                $guidely->continueTrace([]);
                $guidely->flush();
            };
            $listen(self::JOB_PROCESSED, static fn () => $done(false));
            $listen(self::JOB_EXCEPTION, static fn () => $done(true));
            $listen(self::JOB_FAILED, static fn () => $done(true));
        }

        if ($errors && $app->bound('Illuminate\Contracts\Debug\ExceptionHandler')) {
            $handler = $app->make('Illuminate\Contracts\Debug\ExceptionHandler');
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(static function (\Throwable $e) use ($guidely, $app): void {
                    $request = $app->runningInConsole() || !$app->bound('request') ? [] : RequestContext::of(...array_values(self::request($app->make('request'))));
                    $guidely->captureException($e, $request + ['handled' => false, 'mechanism' => 'laravel']);
                });
            }
        }
    }

    /**
     * A Laravel / Symfony request as `['method', 'url', 'headers']`.
     *
     * @return array{method: string, url: string, headers: array<string, list<string>>}
     */
    public static function request(object $request): array
    {
        $headers = isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all') ? $request->headers->all() : [];
        return [
            'method' => method_exists($request, 'getMethod') ? (string) $request->getMethod() : 'GET',
            'url' => method_exists($request, 'getUri') ? (string) $request->getUri() : '/',
            'headers' => is_array($headers) ? $headers : [],
        ];
    }

    private static function status(mixed $response): ?int
    {
        return is_object($response) && method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null;
    }
}
