<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Flags\Tests\Fixtures\TraceFixture;
use GuidelyTech\Track\ErrorCapture;
use GuidelyTech\Track\ErrorOptions;
use GuidelyTech\Track\GuidelyTrack;
use GuidelyTech\Track\Level;
use GuidelyTech\Track\Messages;
use GuidelyTech\Track\RequestContext;
use GuidelyTech\Track\StackTrace;
use GuidelyTech\Track\TrackException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/TraceFixture.php';

final class ErrorCaptureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/TraceFixture.php';

    /** @var list<TrackException> */
    private array $errors = [];

    private function client(FakeHttpClient $http, ?ErrorOptions $options = null, array $more = []): GuidelyTrack
    {
        $errors = &$this->errors;
        return new GuidelyTrack('gly_sk_test_123', ...array_merge([
            'environment' => 'production',
            'httpClient' => $http,
            'onError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e;
            },
            'flushOnShutdown' => false,
            'sleep' => static function (int $ms): void {
            },
            'random' => static fn (): float => 0.5,
            'errors' => $options ?? new ErrorOptions(serverName: 'web-1'),
        ], $more));
    }

    private static function accepted(): FakeHttpClient
    {
        return new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
    }

    /** @return list<array<string, mixed>> every message sent */
    private static function sent(FakeHttpClient $http): array
    {
        $out = [];
        foreach ($http->requests as $r) {
            foreach (json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR)['batch'] as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** Runs $calls on a client and returns what it sent. */
    private function capture(?ErrorOptions $options, callable $calls, array $more = []): array
    {
        $http = self::accepted();
        $client = $this->client($http, $options, $more);
        $calls($client);
        $client->flush();
        return self::sent($http);
    }

    private function captureOne(?ErrorOptions $options, callable $calls, array $more = []): array
    {
        $sent = $this->capture($options, $calls, $more);
        self::assertCount(1, $sent);
        return $sent[0];
    }

    private static function thrown(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('nothing was thrown');
    }

    // --- traces -----------------------------------------------------------------------------------

    public function testPairsEachLocationWithTheFunctionItRunsIn(): void
    {
        $e = self::thrown(static fn () => TraceFixture::wrapped())->getPrevious();
        self::assertInstanceOf(\RuntimeException::class, $e);
        $frames = StackTrace::fromThrowable($e);
        $cls = TraceFixture::class;
        // innermost first: the throw site runs in the closure, each call site in its caller
        self::assertSame([self::FIXTURE, 19], [$frames[0]['file'], $frames[0]['line']]);
        self::assertStringStartsWith("$cls::", (string) $frames[0]['function']);
        self::assertStringContainsString('{closure', (string) $frames[0]['function']); // `{closure}` before PHP 8.4, `{closure:…}` after
        self::assertSame([self::FIXTURE, 21, "$cls::inner"], array_values($frames[1]));
        self::assertSame([self::FIXTURE, 13, "$cls::outer"], array_values($frames[2]));
        self::assertSame([self::FIXTURE, 27, "$cls::wrapped"], array_values($frames[3]));
        self::assertSame(__FILE__, $frames[4]['file']);
        self::assertStringContainsString('{closure', (string) $frames[4]['function']); // the arrow fn in this test
    }

    public function testInternalFunctionsHaveNoFile(): void
    {
        $frames = StackTrace::fromThrowable(self::thrown(static fn () => TraceFixture::mapped()));
        self::assertSame([self::FIXTURE, 36], [$frames[0]['file'], $frames[0]['line']]);
        self::assertSame([null, null, 'array_map'], array_values($frames[1]));
        self::assertSame(self::FIXTURE, $frames[2]['file']);
        self::assertSame(TraceFixture::class . '::mapped', $frames[2]['function']);
    }

    public function testErrorTracesStartAtTheErrorAndSkipTheHandler(): void
    {
        // A warning in user code: the handler is called from the error's own location.
        $backtrace = [
            ['file' => '/app/src/Cart.php', 'line' => 20, 'function' => 'handleError', 'class' => 'GuidelyTech\\Track\\ErrorHandler', 'type' => '->'],
            ['file' => '/app/public/index.php', 'line' => 3, 'function' => 'total', 'class' => 'App\\Cart', 'type' => '->'],
        ];
        self::assertSame([
            ['file' => '/app/src/Cart.php', 'line' => 20, 'function' => 'App\\Cart::total'],
            ['file' => '/app/public/index.php', 'line' => 3, 'function' => null],
        ], StackTrace::forError('/app/src/Cart.php', 20, $backtrace));

        // A warning raised inside an internal function: its call site is the error location.
        $backtrace = [
            ['function' => 'handleError', 'class' => 'GuidelyTech\\Track\\ErrorHandler', 'type' => '->'],
            ['file' => '/app/src/Feed.php', 'line' => 8, 'function' => 'file_get_contents'],
            ['file' => '/app/public/index.php', 'line' => 4, 'function' => 'load', 'class' => 'App\\Feed', 'type' => '::'],
        ];
        self::assertSame([
            ['file' => '/app/src/Feed.php', 'line' => 8, 'function' => 'App\\Feed::load'],
            ['file' => '/app/public/index.php', 'line' => 4, 'function' => null],
        ], StackTrace::forError('/app/src/Feed.php', 8, $backtrace));
    }

    public function testSendsThePreviousChainOldestFirstWithSentryFrames(): void
    {
        $e = self::thrown(static fn () => TraceFixture::wrapped());
        $m = $this->captureOne(new ErrorOptions(serverName: 'web-1', projectRoot: __DIR__), static fn (GuidelyTrack $t) => $t->captureException($e));
        $values = $m['detail']['exception']['values'];
        self::assertCount(2, $values);
        [$cause, $main] = $values;
        self::assertSame('RuntimeException', $cause['type']);
        self::assertSame('from the closure', $cause['value']);
        self::assertSame(['type' => 'chained', 'handled' => true, 'exception_id' => 1, 'parent_id' => 0, 'source' => 'previous'], $cause['mechanism']);
        self::assertSame('LogicException', $main['type']);
        self::assertSame(['type' => 'generic', 'handled' => true, 'exception_id' => 0], $main['mechanism']);

        // detail frames: oldest call first, the throwing frame last
        $frames = $cause['stacktrace']['frames'];
        $top = $frames[count($frames) - 1];
        self::assertSame('fixtures/TraceFixture.php', $top['filename']);
        self::assertSame(self::FIXTURE, $top['abs_path']);
        self::assertSame(19, $top['lineno']);
        self::assertTrue($top['in_app']);
        self::assertStringContainsString('{closure', $top['function']);
        self::assertSame('{main}', $frames[0]['function']);
        self::assertFalse($frames[0]['in_app'], 'vendor/bin/phpunit is library code');

        // properties frames: the thrown exception's, innermost first
        $pf = $m['properties']['frames'];
        self::assertSame(['fn' => TraceFixture::class . '::wrapped', 'file' => 'fixtures/TraceFixture.php', 'line' => 29, 'inApp' => true], $pf[0]);
        self::assertSame('LogicException', $m['properties']['type']);
        self::assertSame('wrapped', $m['properties']['message']);
    }

    public function testStopsAtCyclesAndAfterTwentyPreviousExceptions(): void
    {
        $a = new \RuntimeException('a');
        $b = new \RuntimeException('b', 0, $a);
        // Throwable::$previous is private to Exception: close the loop with reflection.
        $prop = new \ReflectionProperty(\Exception::class, 'previous');
        $prop->setValue($a, $b);
        self::assertSame([$b, $a], ErrorCapture::chain($b));

        $deep = new \RuntimeException('0');
        for ($i = 1; $i < 30; $i++) {
            $deep = new \RuntimeException((string) $i, 0, $deep);
        }
        $m = $this->captureOne(null, static fn (GuidelyTrack $t) => $t->captureException($deep));
        $values = $m['detail']['exception']['values'];
        self::assertCount(20, $values);
        self::assertSame('29', $values[19]['value']);
    }

    public function testInAppFollowsIncludesThenExcludes(): void
    {
        $c = new ErrorCapture(new ErrorOptions(inAppInclude: ['/srv/app/vendor/acme/'], inAppExclude: ['/srv/app/generated/']), 'production', null, static fn () => 0.0);
        self::assertTrue($c->inApp('/srv/app/src/Cart.php'));
        self::assertFalse($c->inApp('/srv/app/vendor/symfony/http-kernel/Kernel.php'));
        self::assertFalse($c->inApp('C:\\app\\vendor\\laravel\\Router.php'));
        self::assertTrue($c->inApp('/srv/app/vendor/acme/billing/Invoice.php'));
        self::assertFalse($c->inApp('/srv/app/generated/Proxy.php'));
        self::assertFalse($c->inApp(null));
    }

    // --- message shape -----------------------------------------------------------------------------

    public function testSendsAnExceptionMessage(): void
    {
        $http = self::accepted();
        $t = $this->client($http, new ErrorOptions(serverName: 'web-1', environment: 'eu-1'), ['appVersion' => '2.4.1']);
        $t->setTag('area', 'checkout');
        $t->setExtra('cartSize', 3);
        $t->setContext('payment', ['provider' => 'stripe']);
        $t->setUser(['id' => 'u_42', 'email' => 'ana@example.com']);
        $t->addBreadcrumb(['category' => 'db.query', 'message' => 'SELECT 1', 'data' => ['rows' => 1, 'password' => 'x']]);
        $line = __LINE__ + 1;
        $id = $t->captureException(new \InvalidArgumentException('bad cart'), [
            'accountId' => 'acme',
            'tags' => ['step' => 'pay'],
            'fingerprint' => ['{{ default }}', 'checkout'],
            'cartId' => 'c_1',
        ]);
        self::assertIsString($id);
        self::assertSame(1, $t->queuedCount(), 'errors go out with the end-of-request flush');
        $t->flush();
        $m = self::sent($http)[0];
        self::assertSame('exception', $m['type']);
        self::assertSame($id, $m['messageId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $m['timestamp']);
        self::assertSame('u_42', $m['userId']);
        self::assertSame('acme', $m['accountId']);
        self::assertSame('2.4.1', $m['appVersion']);
        self::assertArrayNotHasKey('anonymousId', $m);

        $p = $m['properties'];
        self::assertSame(['type', 'message', 'frames', 'source', 'handled', 'level', 'platform', 'mechanism', 'release', 'fingerprintParts', 'cartId'], array_keys($p));
        self::assertSame(['InvalidArgumentException', 'bad cart', 'manual', true, 'error', 'php', 'generic', '2.4.1'], [$p['type'], $p['message'], $p['source'], $p['handled'], $p['level'], $p['platform'], $p['mechanism'], $p['release']]);
        self::assertSame(['{{ default }}', 'checkout'], $p['fingerprintParts']);
        self::assertSame(['fn' => self::class . '::testSendsAnExceptionMessage', 'file' => __FILE__, 'line' => $line, 'inApp' => true], $p['frames'][0]);

        $d = $m['detail'];
        self::assertSame(['php', 'error', '2.4.1', 'eu-1', 'web-1'], [$d['platform'], $d['level'], $d['release'], $d['environment'], $d['server_name']]);
        self::assertIsFloat($d['timestamp']);
        self::assertSame(['area' => 'checkout', 'step' => 'pay'], $d['tags']);
        self::assertSame(['cartSize' => 3], $d['extra']);
        self::assertSame(['id' => 'u_42', 'email' => 'ana@example.com'], $d['user']);
        self::assertSame(['{{ default }}', 'checkout'], $d['fingerprint']);
        self::assertSame(['name' => 'php', 'version' => PHP_VERSION, 'sapi' => PHP_SAPI], $d['contexts']['runtime']);
        self::assertSame(PHP_OS_FAMILY, $d['contexts']['os']['name']);
        self::assertSame(php_uname('r'), $d['contexts']['os']['version']);
        self::assertSame(['provider' => 'stripe'], $d['contexts']['payment']);
        $crumb = $d['breadcrumbs']['values'][0];
        self::assertSame(['default', 'db.query', 'SELECT 1', 'info', ['rows' => 1]], [$crumb['type'], $crumb['category'], $crumb['message'], $crumb['level'], $crumb['data']]);
        self::assertSame(['name' => 'guidely-php', 'version' => GuidelyFlags::VERSION], $d['sdk']);
        self::assertSame([], $this->errors);
    }

    public function testCapturesMessages(): void
    {
        $m = $this->captureOne(null, static fn (GuidelyTrack $t) => $t->captureMessage('Disk almost full', Level::Warning));
        self::assertSame(['Message', 'Disk almost full', [], 'message', 'warning'], [$m['properties']['type'], $m['properties']['message'], $m['properties']['frames'], $m['properties']['source'], $m['properties']['level']]);
        self::assertArrayNotHasKey('mechanism', $m['properties']);
        self::assertSame(['formatted' => 'Disk almost full'], $m['detail']['message']);
        self::assertArrayNotHasKey('exception', $m['detail']);
        self::assertSame('info', $this->captureOne(null, static fn (GuidelyTrack $t) => $t->captureMessage('hi'))['properties']['level']);
        self::assertSame('debug', $this->captureOne(null, static fn (GuidelyTrack $t) => $t->captureMessage('hi', 'debug'))['properties']['level']);
    }

    public function testKeepsTheLastHundredBreadcrumbs(): void
    {
        $m = $this->captureOne(null, static function (GuidelyTrack $t): void {
            for ($i = 0; $i < 130; $i++) {
                $t->addBreadcrumb(['category' => 'step', 'message' => "crumb $i"]);
            }
            $t->captureException(new \RuntimeException('x'));
        });
        $crumbs = $m['detail']['breadcrumbs']['values'];
        self::assertCount(100, $crumbs);
        self::assertSame('crumb 30', $crumbs[0]['message']);
        self::assertSame('crumb 129', $crumbs[99]['message']);
    }

    public function testScopeSettersReplaceAndRemove(): void
    {
        $m = $this->captureOne(null, static function (GuidelyTrack $t): void {
            $t->setTag('a', '1');
            $t->setTag('a', null);
            $t->setTags(['b' => '2']);
            $t->setExtras(['e' => 1, 'apiKey' => 'k']);
            $t->setContext('payment', ['p' => 1]);
            $t->setContext('payment', null);
            $t->setUser(['id' => 'u_1']);
            $t->setUser(null);
            $t->captureException(new \RuntimeException('x'), ['anonymousId' => 'anon_1', 'contexts' => ['job' => ['name' => 'export']]]);
        });
        $d = $m['detail'];
        self::assertSame(['b' => '2'], $d['tags']);
        self::assertSame(['e' => 1], $d['extra']);
        self::assertArrayNotHasKey('payment', $d['contexts']);
        self::assertSame(['name' => 'export'], $d['contexts']['job']);
        self::assertArrayNotHasKey('user', $d);
        self::assertArrayNotHasKey('userId', $m);
        self::assertSame('anon_1', $m['anonymousId']);
    }

    // --- options -----------------------------------------------------------------------------------

    public function testBeforeSendCanEditDropOrThrow(): void
    {
        $edited = $this->captureOne(new ErrorOptions(beforeSend: static function (array $event, ?\Throwable $e): array {
            $event['properties']['message'] = '[redacted]';
            $event['detail']['transaction'] = 'ExportJob';
            $event['userId'] = 'u_9';
            return $event;
        }), static fn (GuidelyTrack $t) => $t->captureException(new \RuntimeException('secret value')));
        self::assertSame('[redacted]', $edited['properties']['message']);
        self::assertSame('ExportJob', $edited['detail']['transaction']);
        self::assertSame('u_9', $edited['userId']);

        $sent = $this->capture(new ErrorOptions(beforeSend: static fn (array $event, ?\Throwable $e) => $e instanceof \LogicException ? null : $event), static function (GuidelyTrack $t): void {
            self::assertNull($t->captureException(new \LogicException('drop me')));
        });
        self::assertSame([], $sent);

        $unchanged = $this->captureOne(new ErrorOptions(beforeSend: static function (array $event): array {
            throw new \RuntimeException('hook bug');
        }), static fn (GuidelyTrack $t) => $t->captureException(new \RuntimeException('original')));
        self::assertSame('original', $unchanged['properties']['message']);
    }

    public function testSamplesWithSampleRate(): void
    {
        // the client's random() is 0.5
        self::assertSame([], $this->capture(new ErrorOptions(sampleRate: 0.4), static fn (GuidelyTrack $t) => $t->captureException(new \RuntimeException('x'))));
        self::assertCount(1, $this->capture(new ErrorOptions(sampleRate: 0.6), static fn (GuidelyTrack $t) => $t->captureException(new \RuntimeException('x'))));
        self::assertSame([], $this->capture(new ErrorOptions(sampleRate: 0.0), static fn (GuidelyTrack $t) => $t->captureMessage('x')));
    }

    public function testIgnoresMatchingErrors(): void
    {
        $sent = $this->capture(new ErrorOptions(
            ignoreErrors: ['ClientAbort'],
            ignorePatterns: ['/^Broken pipe/'],
            ignoreExceptions: [\DomainException::class],
        ), static function (GuidelyTrack $t): void {
            $t->captureException(new \RuntimeException('Broken pipe (write failed)'));
            $t->captureException(new \UnexpectedValueException('ClientAbort: gone'));
            $t->captureException(new \DomainException('subclass of LogicException'));
            $t->captureMessage('ClientAbort happened');
            $t->captureException(new \RuntimeException('Connection reset'));
        });
        self::assertCount(1, $sent);
        self::assertSame('Connection reset', $sent[0]['properties']['message']);
    }

    public function testNeverThrowsFromCapturePaths(): void
    {
        $http = self::accepted();
        $t = $this->client($http, new ErrorOptions(beforeBreadcrumb: static function (array $c): array {
            throw new \RuntimeException('boom');
        }));
        $t->addBreadcrumb(['message' => 'kept despite the hook']);
        self::assertIsString($t->captureException(new \RuntimeException('x'), ['tags' => 'not-an-array', 'fingerprint' => 'nope', 'level' => 'loud', 'extra' => 7]));
        self::assertNull($t->captureException(new \RuntimeException('y'), ['handle' => fopen('php://memory', 'r')])); // not JSON: dropped and reported
        self::assertContains(TrackException::INVALID_MESSAGE, array_map(static fn (TrackException $e) => $e->errorCode, $this->errors));
        $t->close();
        self::assertNull($t->captureException(new \RuntimeException('late')));
        self::assertContains(TrackException::CLOSED, array_map(static fn (TrackException $e) => $e->errorCode, $this->errors));
        $sent = self::sent($http);
        self::assertCount(1, $sent);
        self::assertSame('kept despite the hook', $sent[0]['detail']['breadcrumbs']['values'][0]['message']);
    }

    // --- request -----------------------------------------------------------------------------------

    public function testScrubsTheRequest(): void
    {
        $m = $this->captureOne(null, static fn (GuidelyTrack $t) => $t->captureException(new \RuntimeException('boom'), RequestContext::of('post', 'https://api.example.com/pay?plan=pro&access_token=s3cr3t&card%5Bnumber%5D=4242#frag', [
            'User-Agent' => ['curl/8'],
            'Cookie' => ['sid=abc'],
            'Authorization' => 'Bearer x',
            'X-Api-Key' => 'k',
            'Accept' => ['text/html', 'application/json'],
        ]) + ['handled' => false, 'mechanism' => 'request']));
        self::assertSame(['POST /pay', false, 'request'], [$m['properties']['transaction'], $m['properties']['handled'], $m['properties']['mechanism']]);
        self::assertSame([
            'method' => 'POST',
            'url' => 'https://api.example.com/pay',
            'query_string' => 'plan=pro',
            'headers' => ['User-Agent' => 'curl/8', 'Accept' => 'text/html, application/json'],
        ], $m['detail']['request']);
        self::assertSame('POST /pay', $m['detail']['transaction']);
    }

    public function testReadsTheRequestFromServerGlobals(): void
    {
        $ctx = RequestContext::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'app.example.com',
            'REQUEST_URI' => '/orders/7?tab=items',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_COOKIE' => 'sid=1',
            'CONTENT_TYPE' => 'application/json',
        ], 'fpm-fcgi');
        self::assertSame('GET /orders/7', $ctx['transaction']);
        self::assertSame('https://app.example.com/orders/7?tab=items', $ctx['request']['url']);
        self::assertSame(['Host' => 'app.example.com', 'User-Agent' => 'Mozilla/5.0', 'Cookie' => 'sid=1', 'Content-Type' => 'application/json'], $ctx['request']['headers']);
        self::assertSame([], RequestContext::fromGlobals(['REQUEST_METHOD' => 'GET'], 'cli'));
    }

    // --- size limits -------------------------------------------------------------------------------

    public function testTrimsBreadcrumbsOldestFirstToFitTheDetailBudget(): void
    {
        $big = str_repeat('x', 1000);
        $m = $this->captureOne(null, static function (GuidelyTrack $t) use ($big): void {
            for ($i = 0; $i < 100; $i++) {
                $t->addBreadcrumb(['category' => 'log', 'message' => "#$i $big"]);
            }
            $t->captureException(new \RuntimeException('x'));
        });
        self::assertLessThanOrEqual(ErrorCapture::MAX_DETAIL_BYTES, ErrorCapture::bytes($m['detail']));
        $crumbs = $m['detail']['breadcrumbs']['values'];
        self::assertGreaterThan(40, count($crumbs));
        self::assertLessThan(100, count($crumbs));
        self::assertStringStartsWith('#99 ', $crumbs[count($crumbs) - 1]['message']);
        self::assertSame([], $this->errors);
    }

    public function testFitTrimsInTheContractOrderAndGivesUpWhole(): void
    {
        $d = [
            'exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => [['function' => 'f', 'vars' => ['blob' => str_repeat('v', 5000)]]]]]]],
            'threads' => ['values' => [['id' => 1, 'crashed' => true], ['id' => 2, 'name' => str_repeat('n', 4500)]]],
        ];
        $fitted = ErrorCapture::fit($d, 4000);
        self::assertNotNull($fitted);
        self::assertArrayNotHasKey('vars', $fitted['exception']['values'][0]['stacktrace']['frames'][0]);
        self::assertSame([['id' => 1, 'crashed' => true]], $fitted['threads']['values']);

        $frames = [];
        for ($i = 0; $i < 250; $i++) {
            $frames[] = ['function' => "f$i", 'filename' => str_repeat('p', 40) . '.php'];
        }
        $chain = ['exception' => ['values' => array_fill(0, 20, ['type' => 'E', 'stacktrace' => ['frames' => $frames]])]];
        $fitted = ErrorCapture::fit($chain, ErrorCapture::MAX_DETAIL_BYTES);
        self::assertNotNull($fitted);
        $kept = $fitted['exception']['values'][0]['stacktrace']['frames'];
        self::assertLessThan(250, count($kept));
        self::assertSame('f249', $kept[count($kept) - 1]['function'], 'the innermost frames are kept');

        self::assertNull(ErrorCapture::fit(['user' => ['id' => str_repeat('u', 5000)]], 4000));
    }

    public function testSendsExceptionsLargerThanTheTrackMessageLimit(): void
    {
        $text = str_repeat('abcdefghijklmnopqrstuvwxyz', 350);
        $e = new \RuntimeException($text);
        for ($i = 0; $i < 3; $i++) {
            $e = new \RuntimeException($text, 0, $e);
        }
        $http = self::accepted();
        $t = $this->client($http);
        $t->captureException($e);
        $t->flush();
        $body = (string) $http->requests[0]['body'];
        self::assertGreaterThan(Messages::MAX_MESSAGE_BYTES, strlen($body));
        self::assertLessThanOrEqual(Messages::MAX_EXCEPTION_MESSAGE_BYTES + 200, strlen($body));
        self::assertSame(1000, strlen(self::sent($http)[0]['properties']['message']));
    }
}
