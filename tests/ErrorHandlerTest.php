<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Track\ErrorHandler;
use GuidelyTech\Track\ErrorOptions;
use GuidelyTech\Track\GuidelyTrack;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private FakeHttpClient $http;
    private GuidelyTrack $client;
    /** @var list<\Throwable> */
    private array $reports = [];
    private int $errorReporting;

    protected function setUp(): void
    {
        // PHPUnit narrows error_reporting() while a test runs (it handles the rest itself).
        $this->errorReporting = error_reporting(E_ALL);
        $this->http = new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
        $this->client = new GuidelyTrack('gly_sk_test_123', environment: 'production', httpClient: $this->http, flushOnShutdown: false,
            onError: function (\Throwable $e): void {
                $this->reports[] = $e;
            },
            sleep: static function (int $ms): void {
            }, errors: new ErrorOptions(serverName: 'web-1'));
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        self::assertSame([], array_map(static fn (\Throwable $e) => $e->getMessage(), $this->reports));
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        $out = [];
        foreach ($this->http->requests as $r) {
            foreach (json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR)['batch'] as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public function testUncaughtExceptionsAreFlushedCrashesThenPassedOn(): void
    {
        $handler = new ErrorHandler($this->client);
        $passed = [];
        $requestsWhenPassed = null;
        $handler->chainTo(function (\Throwable $e) use (&$passed, &$requestsWhenPassed): void {
            $passed[] = $e;
            $requestsWhenPassed = count($this->http->requests);
        }, null);
        $crash = new \RuntimeException('worker died');
        $handler->handleException($crash);

        self::assertSame([$crash], $passed);
        self::assertSame(1, $requestsWhenPassed, 'flushed before the previous handler runs');
        $m = $this->sent()[0];
        self::assertSame('exception', $m['type']);
        self::assertSame(['fatal', false, 'uncaught', 'uncaught'], [$m['properties']['level'], $m['properties']['handled'], $m['properties']['mechanism'], $m['properties']['source']]);
        self::assertSame(['type' => 'uncaught', 'handled' => false, 'exception_id' => 0], $m['detail']['exception']['values'][0]['mechanism']);
        self::assertArrayNotHasKey('request', $m['detail'], 'no request on the CLI');
    }

    public function testRethrowsWhenNoHandlerWasInstalledBefore(): void
    {
        $handler = new ErrorHandler($this->client);
        $crash = new \RuntimeException('nobody else');
        $previous = set_exception_handler(null);
        try {
            $handler->handleException($crash);
            self::fail('not rethrown');
        } catch (\RuntimeException $e) {
            self::assertSame($crash, $e);
        } finally {
            set_exception_handler($previous);
        }
        self::assertCount(1, $this->sent());
    }

    public function testWarningsBecomeEventsAndNoticesBreadcrumbs(): void
    {
        $handler = new ErrorHandler($this->client);
        $calls = [];
        $handler->chainTo(null, static function (int $level, string $message, string $file, int $line) use (&$calls): bool {
            $calls[] = [$level, $message];
            return true;
        });
        self::assertTrue($handler->handleError(E_USER_NOTICE, 'cache cold', __FILE__, __LINE__));
        self::assertTrue($handler->handleError(E_WARNING, 'Undefined array key "x"', __FILE__, __LINE__));
        $warningLine = __LINE__ - 1;
        self::assertSame([[E_USER_NOTICE, 'cache cold'], [E_WARNING, 'Undefined array key "x"']], $calls);
        self::assertSame(0, count($this->http->requests), 'PHP errors go out with the end-of-request flush');
        $this->client->flush();

        $sent = $this->sent();
        self::assertCount(1, $sent);
        $p = $sent[0]['properties'];
        self::assertSame(['ErrorException', 'Warning: Undefined array key "x"', 'warning', false, 'error_handler'], [$p['type'], $p['message'], $p['level'], $p['handled'], $p['mechanism']]);
        self::assertSame(['fn' => self::class . '::testWarningsBecomeEventsAndNoticesBreadcrumbs', 'file' => __FILE__, 'line' => $warningLine, 'inApp' => true], $p['frames'][0]);
        $crumb = $sent[0]['detail']['breadcrumbs']['values'][0];
        self::assertSame(['error', 'error_reporting', 'Notice: cache cold', 'info'], [$crumb['type'], $crumb['category'], $crumb['message'], $crumb['level']]);
    }

    public function testRespectsErrorReportingAndFallsBackToPhp(): void
    {
        $handler = new ErrorHandler($this->client);
        $handler->chainTo(null, null);
        $old = error_reporting(E_ALL & ~E_WARNING);
        try {
            self::assertFalse($handler->handleError(E_WARNING, 'silenced', __FILE__, __LINE__));
        } finally {
            error_reporting($old);
        }
        self::assertFalse($handler->handleError(E_DEPRECATED, 'old api', __FILE__, __LINE__), 'false: PHP\'s own handler still runs');
        $this->client->captureMessage('flush marker');
        $this->client->flush();
        $sent = $this->sent();
        self::assertCount(1, $sent);
        $crumbs = $sent[0]['detail']['breadcrumbs']['values'];
        self::assertSame(['Deprecated: old api'], array_column($crumbs, 'message'));
    }

    public function testEventLevelsAreConfigurable(): void
    {
        $handler = new ErrorHandler($this->client, eventLevels: E_ALL);
        $handler->chainTo(null, null);
        $handler->handleError(E_USER_DEPRECATED, 'deprecated call', __FILE__, __LINE__);
        $this->client->flush();
        self::assertSame('Deprecated: deprecated call', $this->sent()[0]['properties']['message']);
        self::assertSame('info', $this->sent()[0]['properties']['level']);
    }

    public function testReportsFatalErrorsAtShutdownAndFlushes(): void
    {
        $handler = new ErrorHandler($this->client, lastError: static fn (): array => [
            'type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', 'file' => '/app/src/Report.php', 'line' => 88,
        ]);
        $limit = ini_get('memory_limit');
        try {
            $handler->handleShutdown();
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
        self::assertCount(1, $this->http->requests, 'sent at once');
        $m = $this->sent()[0];
        $p = $m['properties'];
        self::assertSame(['FatalError', 'fatal', false, 'fatal_error'], [$p['type'], $p['level'], $p['handled'], $p['mechanism']]);
        self::assertSame([['fn' => '{main}', 'file' => '/app/src/Report.php', 'line' => 88, 'inApp' => true]], $p['frames']);
        self::assertSame([['filename' => '/app/src/Report.php', 'function' => '{main}', 'abs_path' => '/app/src/Report.php', 'lineno' => 88, 'in_app' => true]], $m['detail']['exception']['values'][0]['stacktrace']['frames']);
    }

    public function testIgnoresNonFatalAndAlreadyReportedErrorsAtShutdown(): void
    {
        (new ErrorHandler($this->client, lastError: static fn (): ?array => null))->handleShutdown();
        (new ErrorHandler($this->client, lastError: static fn (): array => ['type' => E_WARNING, 'message' => 'x', 'file' => 'a.php', 'line' => 1]))->handleShutdown();

        $handler = new ErrorHandler($this->client, lastError: static fn (): array => ['type' => E_ERROR, 'message' => 'Uncaught RuntimeException: boom in /app/x.php:3', 'file' => '/app/x.php', 'line' => 3]);
        $handler->chainTo(static function (): void {
        }, null);
        $handler->handleException(new \RuntimeException('boom'));
        $handler->handleShutdown();
        self::assertCount(1, $this->sent(), 'the uncaught exception, not a second "Uncaught …" fatal');

        (new ErrorHandler($this->client, lastError: static fn (): array => ['type' => E_PARSE, 'message' => 'syntax error', 'file' => '/app/y.php', 'line' => 9]))->handleShutdown();
        self::assertSame('ParseError', $this->sent()[1]['properties']['type']);
    }

    public function testRegisterChainsTheInstalledHandlers(): void
    {
        $prevException = static function (\Throwable $e): void {
        };
        $prevError = static fn (): bool => true;
        set_exception_handler($prevException);
        set_error_handler($prevError);
        try {
            $handler = ErrorHandler::register($this->client, fatalErrors: false);
            self::assertSame($prevException, $handler->previousExceptionHandler());
            self::assertSame($prevError, $handler->previousErrorHandler());
            self::assertSame([$handler, 'handleError'], set_error_handler(static fn (): bool => false));
            restore_error_handler();
            self::assertSame([$handler, 'handleException'], set_exception_handler(null));
            restore_exception_handler();
        } finally {
            restore_error_handler();
            restore_error_handler();
            restore_exception_handler();
            restore_exception_handler();
        }
    }
}
