<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Track\ErrorOptions;
use GuidelyTech\Track\GuidelyTrack;
use GuidelyTech\Track\Laravel;
use GuidelyTech\Track\Span;
use GuidelyTech\Track\Sql;
use GuidelyTech\Track\TracingOptions;
use GuidelyTech\Track\TrackException;
use PHPUnit\Framework\TestCase;

final class TracingTest extends TestCase
{
    /** @var list<TrackException> */
    private array $errors = [];
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->errors = [];
        $this->http = new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
    }

    private function client(?TracingOptions $tracing = null, array $more = []): GuidelyTrack
    {
        $errors = &$this->errors;
        return new GuidelyTrack('gly_sk_test_123', ...array_merge([
            'environment' => 'production',
            'httpClient' => $this->http,
            'onError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e;
            },
            'flushOnShutdown' => false,
            'sleep' => static function (int $ms): void {
            },
            'random' => static fn (): float => 0.5,
            'errors' => new ErrorOptions(serverName: 'web-1'),
            'tracing' => $tracing ?? new TracingOptions(tracesSampleRate: 1.0),
        ], $more));
    }

    /** @return list<array<string, mixed>> */
    private function sent(?string $type = null): array
    {
        $out = [];
        foreach ($this->http->requests as $r) {
            foreach (json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR)['batch'] as $m) {
                if ($type === null || $m['type'] === $type) {
                    $out[] = $m;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the transaction events sent */
    private function transactions(): array
    {
        return array_map(static fn (array $m) => $m['transaction'], $this->sent('transaction'));
    }

    // --- spans ----------------------------------------------------------------------------------

    public function testNothingIsSentWithoutASampleRateButCallbacksStillRun(): void
    {
        $g = $this->client(new TracingOptions());
        $out = $g->startSpan(['name' => 'work'], function (Span $span) use ($g) {
            self::assertFalse($span->isRecording());
            self::assertSame($span, $g->getActiveSpan());
            return 42;
        });
        self::assertSame(42, $out);
        self::assertNull($g->traceRequest(['method' => 'GET', 'url' => '/']));
        $g->flush();
        self::assertSame([], $this->sent());
    }

    public function testARootSpanAndItsChildrenBecomeOneSentryShapedTransaction(): void
    {
        $g = $this->client(null, ['appVersion' => '2.4.1']);
        $g->setTag('region', 'eu');
        $g->setUser(['id' => 'u_42', 'email' => 'ada@example.com']);
        $result = $g->startSpan(['name' => 'nightly-export', 'op' => 'queue.process', 'attributes' => ['queue' => 'exports', 'password' => 'x']], function (Span $root) use ($g) {
            self::assertTrue($root->isRecording());
            $g->startSpan(['name' => 'load rows', 'op' => 'function'], fn () => $g->startSpan(['name' => 'inner'], fn () => null));
            try {
                $g->startSpan(['name' => 'upload', 'op' => 'http.client'], static function (): void {
                    throw new \RuntimeException('S3 down');
                });
                self::fail('not rethrown');
            } catch (\RuntimeException $e) {
                self::assertSame('S3 down', $e->getMessage());
            }
            $child = $g->startInactiveSpan(['name' => 'GET /x', 'op' => 'http.client']);
            $child->setHttpStatus(404)->setAttribute('retry', true)->updateName('GET /items/*');
            $child->end();
            $g->startInactiveSpan(['name' => 'left open']); // never ended: not sent
            return 'done';
        });
        self::assertSame('done', $result);
        $g->flush();
        [$m] = $this->sent('transaction');
        self::assertSame('u_42', $m['userId']);
        $tx = $m['transaction'];
        self::assertSame($m['messageId'], $tx['event_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tx['event_id']);
        self::assertSame(['type' => 'transaction', 'transaction' => 'nightly-export', 'transaction_info' => ['source' => 'custom'], 'platform' => 'php', 'release' => '2.4.1', 'environment' => 'production', 'server_name' => 'web-1', 'tags' => ['region' => 'eu'], 'user' => ['id' => 'u_42', 'email' => 'ada@example.com']], array_intersect_key($tx, array_flip(['type', 'transaction', 'transaction_info', 'platform', 'release', 'environment', 'server_name', 'tags', 'user'])));
        $trace = $tx['contexts']['trace'];
        self::assertSame(['queue.process', 'ok', 'manual'], [$trace['op'], $trace['status'], $trace['origin']]);
        self::assertSame(['queue' => 'exports', 'sentry.sample_rate' => 1], $trace['data']);
        self::assertSame('php', $tx['contexts']['runtime']['name']);
        $by = array_column($tx['spans'], null, 'description');
        self::assertEqualsCanonicalizing(['load rows', 'inner', 'upload', 'GET /items/*'], array_keys($by));
        self::assertSame($trace['span_id'], $by['load rows']['parent_span_id']);
        self::assertSame($by['load rows']['span_id'], $by['inner']['parent_span_id']);
        self::assertSame($trace['trace_id'], $by['inner']['trace_id']);
        self::assertSame('internal_error', $by['upload']['status']);
        self::assertSame('not_found', $by['GET /items/*']['status']);
        self::assertEquals(['retry' => true, 'http.response.status_code' => 404], $by['GET /items/*']['data']);
        foreach ($tx['spans'] as $s) {
            self::assertGreaterThanOrEqual($s['start_timestamp'], $s['timestamp']);
        }
        self::assertNull($g->getActiveSpan());
    }

    public function testSamplerContinueTraceAndHeaders(): void
    {
        $seen = [];
        $g = $this->client(new TracingOptions(tracesSampler: function (array $ctx) use (&$seen) {
            $seen[] = $ctx['name'];
            return $ctx['name'] === 'health' ? false : ($ctx['parentSampled'] ?? 0.0);
        }));
        $g->startSpan(['name' => 'health'], fn () => $g->startSpan(['name' => 'child'], fn (Span $s) => self::assertFalse($s->isRecording())));
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        self::assertTrue($g->continueTrace(['Sentry-Trace' => "$traceId-00f067aa0ba902b7-1", 'baggage' => 'sentry-trace_id=' . $traceId . ',sentry-environment=staging,other=1']));
        self::assertSame("$traceId-00f067aa0ba902b7-1", $g->getTraceHeaders()['sentry-trace']);
        $g->startSpan(['name' => 'consume', 'op' => 'queue.process'], function (Span $s) use ($g, $traceId) {
            $h = $g->getTraceHeaders();
            self::assertSame("sentry-trace_id=$traceId,sentry-environment=staging", $h['baggage']);
            self::assertSame("00-$traceId-{$s->spanId}-01", $h['traceparent']);
        });
        self::assertTrue($g->continueTrace(['traceparent' => "00-$traceId-00f067aa0ba902b7-00"]));
        $g->startSpan(['name' => 'not sampled upstream'], fn () => null);
        self::assertFalse($g->continueTrace(['sentry-trace' => 'nope']));
        self::assertSame([], $g->getTraceHeaders());
        $g->flush();
        self::assertSame(['health', 'consume', 'not sampled upstream'], $seen); // children are not sampled on their own
        $txs = $this->transactions();
        self::assertSame(['consume'], array_column($txs, 'transaction'));
        self::assertSame([$traceId, '00f067aa0ba902b7'], [$txs[0]['contexts']['trace']['trace_id'], $txs[0]['contexts']['trace']['parent_span_id']]);
    }

    public function testErrorsCapturedInASpanLinkToItsTrace(): void
    {
        $g = $this->client();
        $ids = null;
        $g->startSpan(['name' => 'job'], function () use ($g, &$ids) {
            $g->startSpan(['name' => 'step'], function (Span $s) use ($g, &$ids) {
                $ids = $s->spanContext();
                $g->captureException(new \RuntimeException('step failed'));
            });
        });
        $g->flush();
        $trace = $this->sent('exception')[0]['detail']['contexts']['trace'];
        self::assertSame([$ids['traceId'], $ids['spanId']], [$trace['trace_id'], $trace['span_id']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $trace['parent_span_id']);
    }

    public function testIgnoreTransactionsMaxSpansAndOversizedTransactions(): void
    {
        $g = $this->client(new TracingOptions(tracesSampleRate: 1.0, ignoreTransactions: ['GET /health', '/^cron:/'], maxSpans: 2));
        $g->startSpan(['name' => 'outer'], function () use ($g) {
            for ($i = 0; $i < 5; $i++) {
                $g->startSpan(['name' => "step $i"], fn () => null);
            }
        });
        $g->startSpan(['name' => 'GET /health'], fn () => null);
        $g->startSpan(['name' => 'cron:cleanup'], fn () => null);
        $big = $this->client();
        $big->startSpan(['name' => 'big'], function () use ($big) {
            for ($i = 0; $i < 1000; $i++) {
                $big->startSpan(['name' => $i . ' ' . str_repeat('x', 1900)], fn () => null);
            }
        });
        $g->flush();
        $big->flush();
        [$outer, $large] = $this->transactions();
        self::assertSame('outer', $outer['transaction']);
        self::assertCount(2, $outer['spans']);
        self::assertSame(3, $outer['contexts']['trace']['data']['guidely.dropped_spans']);
        self::assertSame('big', $large['transaction']);
        self::assertGreaterThan(100, count($large['spans']));
        self::assertSame(1000, count($large['spans']) + $large['contexts']['trace']['data']['guidely.dropped_spans']);
        self::assertSame([], $this->errors);
    }

    // --- requests --------------------------------------------------------------------------------

    public function testARequestTransactionContinuesTheCallersTraceAndIsNamedByRoute(): void
    {
        $g = $this->client();
        $traceId = '0af7651916cd43dd8448eb211c80319c';
        $span = $g->traceRequest(['method' => 'post', 'url' => 'https://api.example.com/api/orders/42?token=abc&page=2', 'headers' => ['Sentry-Trace' => ["$traceId-b7ad6b7169203331-1"]]]);
        self::assertNotNull($span);
        self::assertSame($span, $g->getActiveSpan());
        $g->setTag('tenant', 'acme');
        $g->startSpan(['name' => 'charge', 'op' => 'function'], fn () => $g->captureException(new \RuntimeException('card declined')));
        $g->setRoute('api/orders/{order}');
        $g->finishRequest(502);
        self::assertNull($g->getActiveSpan());
        // Without a route: named by its path, ids taken out; ended by close() with no status on the CLI.
        $g->traceRequest(['method' => 'GET', 'url' => '/files/9f1c2b3a4d5e6f708192a3b4/7']);
        $g->close();
        [$pay, $file] = $this->transactions();
        self::assertSame(['POST /api/orders/{order}', 'route', ['tenant' => 'acme']], [$pay['transaction'], $pay['transaction_info']['source'], $pay['tags']]);
        $trace = $pay['contexts']['trace'];
        self::assertSame([$traceId, 'b7ad6b7169203331', 'http.server', 'internal_error', 'auto.http.php'], [$trace['trace_id'], $trace['parent_span_id'], $trace['op'], $trace['status'], $trace['origin']]);
        self::assertSame(['http.request.method' => 'POST', 'url.path' => '/api/orders/42', 'http.route' => '/api/orders/{order}', 'http.response.status_code' => 502], $trace['data']); // the caller decided: no rate of its own
        self::assertSame(['status_code' => 502], $pay['contexts']['response']);
        self::assertSame(['method' => 'POST', 'url' => 'https://api.example.com/api/orders/42', 'query_string' => 'page=2'], $pay['request']);
        self::assertSame(['charge'], array_column($pay['spans'], 'description'));
        self::assertSame(['GET /files/*/*', 'url', 'ok'], [$file['transaction'], $file['transaction_info']['source'], $file['contexts']['trace']['status']]);
        self::assertSame($traceId, $this->sent('exception')[0]['detail']['contexts']['trace']['trace_id']);
    }

    public function testTraceRequestReadsPsr7RequestsAndServerGlobals(): void
    {
        $g = $this->client();
        $psr7 = new class () {
            public function getMethod(): string
            {
                return 'DELETE';
            }

            public function getUri(): string
            {
                return 'https://shop.test/items/3';
            }

            public function getHeaders(): array
            {
                return ['traceparent' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']];
            }
        };
        $g->traceRequest($psr7)?->setHttpStatus(204)->end();
        $g->flush();
        [$tx] = $this->transactions();
        self::assertSame(['DELETE /items/*', '0af7651916cd43dd8448eb211c80319c', 'ok'], [$tx['transaction'], $tx['contexts']['trace']['trace_id'], $tx['contexts']['trace']['status']]);
        self::assertNull($g->traceRequest()); // $_SERVER on the CLI: no request
    }

    // --- queries ---------------------------------------------------------------------------------

    public function testSqlScrubbing(): void
    {
        self::assertSame('SELECT * FROM users WHERE email = ? AND id = ? AND org = $1 LIMIT ?', Sql::scrub("SELECT * FROM users WHERE email = 'ada@example.com' AND id = 42 AND org = $1 LIMIT 10"));
        self::assertSame('SELECT "t1"."name" FROM "t1" WHERE "t1".x = ? AND y = ? AND z = ? AND w = :name2', Sql::scrub("SELECT \"t1\".\"name\" FROM \"t1\" WHERE \"t1\".x = E'it\\'s' AND y = \$\$raw\$\$ AND z = \$tag\$x\$tag\$ AND w = :name2", 'postgresql'));
        self::assertSame('SELECT * FROM `orders` WHERE note = ? AND code = ? AND hex = ? AND user_id = ?', Sql::scrub("SELECT * FROM `orders` WHERE note = \"it\\\"s\" AND code = 'O''Brien' AND hex = 0x1F AND user_id = ?", 'mysql'));
        self::assertSame(['mysql', 'postgresql', 'sqlite', 'mssql', null], [Sql::system('mysql'), Sql::system('pgsql'), Sql::system('sqlite'), Sql::system('sqlsrv'), Sql::system(null)]);
        self::assertSame('UPDATE', Sql::operation('/* app */ update t set a = 1'));
    }

    public function testRecordQueryMakesDbSpansAndBreadcrumbs(): void
    {
        $g = $this->client();
        $g->startSpan(['name' => 'GET /orders', 'op' => 'http.server'], function () use ($g) {
            $g->recordQuery("select * from `orders` where `customer` = 'ada' and `total` > 100", 12.5, ['system' => 'mysql', 'database' => 'shop', 'rows' => 3]);
            $g->recordQuery('insert into missing (a) values (?)', 0.8, ['system' => 'pgsql', 'error' => new \RuntimeException('relation "missing" does not exist')]);
            $g->captureException(new \RuntimeException('after queries'));
        });
        $g->flush();
        [$tx] = $this->transactions();
        [$select, $insert] = $tx['spans'];
        self::assertSame(['db', 'select * from `orders` where `customer` = ? and `total` > ?', 'ok'], [$select['op'], $select['description'], $select['status']]);
        self::assertSame(['db.system' => 'mysql', 'db.name' => 'shop', 'db.operation' => 'SELECT', 'db.response.returned_rows' => 3], $select['data']);
        self::assertEqualsWithDelta(0.0125, $select['timestamp'] - $select['start_timestamp'], 0.002);
        self::assertSame(['internal_error', 'postgresql'], [$insert['status'], $insert['data']['db.system']]);
        $crumbs = $this->sent('exception')[0]['detail']['breadcrumbs']['values'];
        self::assertSame([['query', 'query', 'select * from `orders` where `customer` = ? and `total` > ?', 'info'], ['query', 'query', 'insert into missing (a) values (?)', 'error']], array_map(static fn ($b) => [$b['type'], $b['category'], $b['message'], $b['level']], $crumbs));
        self::assertSame(['db.system' => 'mysql', 'db.name' => 'shop', 'duration_ms' => 12.5, 'rows' => 3], $crumbs[0]['data']);
        self::assertSame('relation "missing" does not exist', $crumbs[1]['data']['error']);
        self::assertStringNotContainsString('ada', json_encode([$tx['spans'], $crumbs]));
    }

    public function testInstrumentPdoRecordsPreparedStatements(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed');
        }
        $g = $this->client();
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        self::assertTrue($g->instrumentPdo($pdo));
        $g->startSpan(['name' => 'signup'], function () use ($pdo) {
            $insert = $pdo->prepare('INSERT INTO users (email) VALUES (?)');
            $insert->execute(['ada@example.com']);
            $insert->execute(['bob@example.com']);
            $select = $pdo->prepare("SELECT * FROM users WHERE email LIKE '%example.com'");
            $select->execute();
            self::assertCount(2, $select->fetchAll());
            try {
                $pdo->prepare('INSERT INTO users (id, email) VALUES (1, ?)')->execute(['dup@example.com']);
                self::fail('no constraint error');
            } catch (\PDOException) {
            }
        });
        $g->flush();
        [$tx] = $this->transactions();
        self::assertSame([
            ['INSERT INTO users (email) VALUES (?)', 'ok', 'auto.db.pdo', 'sqlite'],
            ['INSERT INTO users (email) VALUES (?)', 'ok', 'auto.db.pdo', 'sqlite'],
            ['SELECT * FROM users WHERE email LIKE ?', 'ok', 'auto.db.pdo', 'sqlite'],
            ['INSERT INTO users (id, email) VALUES (?, ?)', 'internal_error', 'auto.db.pdo', 'sqlite'],
        ], array_map(static fn ($s) => [$s['description'], $s['status'], $s['origin'], $s['data']['db.system']], $tx['spans']));
        self::assertStringNotContainsString('example.com', json_encode($tx));
        // A connection with its own statement class is left alone.
        $own = new \PDO('sqlite::memory:');
        $own->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [OwnStatement::class]);
        self::assertFalse($g->instrumentPdo($own));
        self::assertStringContainsString('own statement class', $this->errors[0]->getMessage());
    }

    // --- outgoing HTTP ---------------------------------------------------------------------------

    public function testGuzzleMiddlewareSpansAndHeaders(): void
    {
        $g = $this->client(new TracingOptions(tracesSampleRate: 1.0, tracePropagationTargets: ['/api/', '#^https://partner\.test#']), ['apiUrl' => 'https://guidely.internal']);
        $seen = [];
        $handler = static function (FakePsr7Request $request, array $options) use (&$seen) {
            $seen[] = $request;
            $status = str_contains($request->url, 'missing') ? 404 : 200;
            if (str_contains($request->url, 'down')) {
                return FakePromise::rejected(new \RuntimeException('Connection refused'));
            }
            return FakePromise::fulfilled(new class ($status) {
                public function __construct(private int $s)
                {
                }

                public function getStatusCode(): int
                {
                    return $this->s;
                }
            });
        };
        $send = $g->guzzleMiddleware()($handler);
        $traceId = '';
        $g->startSpan(['name' => 'sync'], function (Span $s) use ($send, &$traceId) {
            $traceId = $s->traceId;
            $send(new FakePsr7Request('GET', 'https://shop.test/api/items?x=1'), [])->wait();
            $send(new FakePsr7Request('POST', 'https://partner.test/missing'), [])->wait();
            $send(new FakePsr7Request('GET', 'https://other.test/page'), [])->wait(); // not a target: span, no headers
            $send(new FakePsr7Request('GET', 'https://guidely.internal/api/v1/sdk/server/batch'), [])->wait(); // Guidely: neither
            $failed = $send(new FakePsr7Request('GET', 'https://shop.test/api/down'), []);
            try {
                $failed->wait();
                self::fail('the rejection was swallowed');
            } catch (\RuntimeException $e) {
                self::assertSame('Connection refused', $e->getMessage());
            }
        });
        $g->flush();
        [$tx] = $this->transactions();
        self::assertSame([
            ['http.client', 'GET https://shop.test/api/items', 'ok'],
            ['http.client', 'POST https://partner.test/missing', 'not_found'],
            ['http.client', 'GET https://other.test/page', 'ok'],
            ['http.client', 'GET https://shop.test/api/down', 'internal_error'],
        ], array_map(static fn ($s) => [$s['op'], $s['description'], $s['status']], $tx['spans']));
        self::assertSame(['http.request.method' => 'GET', 'url.full' => 'https://shop.test/api/items', 'server.address' => 'shop.test', 'http.response.status_code' => 200], $tx['spans'][0]['data']);
        self::assertSame("$traceId-{$tx['spans'][0]['span_id']}-1", $seen[0]->headers['sentry-trace']);
        self::assertSame("00-$traceId-{$tx['spans'][0]['span_id']}-01", $seen[0]->headers['traceparent']);
        self::assertStringContainsString("sentry-trace_id=$traceId", $seen[0]->headers['baggage']);
        self::assertArrayHasKey('sentry-trace', $seen[1]->headers);
        self::assertArrayNotHasKey('sentry-trace', $seen[2]->headers);
        self::assertArrayNotHasKey('sentry-trace', $seen[3]->headers);
    }

    // --- Laravel ---------------------------------------------------------------------------------

    public function testLaravelWiresRequestsQueriesJobsAndErrors(): void
    {
        $g = $this->client();
        $app = new FakeLaravelApp();
        Laravel::instrument($g, $app);
        self::assertSame(1, $app->http->middlewares);
        self::assertNotNull($app->reportable);

        // An Octane request: RequestReceived → RouteMatched → queries → RequestTerminated.
        $request = new FakeLaravelRequest('GET', 'https://app.test/users/7?page=1', ['sentry-trace' => ['0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-1']]);
        $app->dispatch('Laravel\Octane\Events\RequestReceived', (object) ['request' => $request]);
        $app->dispatch('Illuminate\Routing\Events\RouteMatched', (object) ['route' => new class () {
            public function uri(): string
            {
                return 'users/{user}';
            }
        }]);
        $connection = new class () {
            public function getDriverName(): string
            {
                return 'pgsql';
            }

            public function getDatabaseName(): string
            {
                return 'app';
            }
        };
        $app->dispatch('Illuminate\Database\Events\QueryExecuted', (object) ['sql' => 'select * from "users" where "id" = ? limit 1', 'time' => 1.25, 'connection' => $connection]);
        ($app->reportable)(new \RuntimeException('boom'));
        $headersForJobs = \Closure::fromCallable($app->payloadHook)('redis', 'default', []);
        $app->dispatch('Laravel\Octane\Events\RequestTerminated', (object) ['response' => new class () {
            public function getStatusCode(): int
            {
                return 500;
            }
        }]);

        // The job it dispatched, in a worker: the trace carries over through the payload.
        $job = new class ($headersForJobs) {
            public function __construct(private array $extra)
            {
            }

            public function payload(): array
            {
                return ['displayName' => 'App\Jobs\SendInvoice'] + $this->extra;
            }

            public function resolveName(): string
            {
                return 'App\Jobs\SendInvoice';
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function getJobId(): string
            {
                return 'j-1';
            }

            public function attempts(): int
            {
                return 1;
            }
        };
        $app->dispatch('Illuminate\Queue\Events\JobProcessing', (object) ['job' => $job, 'connectionName' => 'redis']);
        $app->dispatch('Illuminate\Database\Events\QueryExecuted', (object) ['sql' => "update invoices set sent = 1 where id = 9", 'time' => 3.0, 'connection' => $connection]);
        $app->dispatch('Illuminate\Queue\Events\JobProcessed', (object) ['job' => $job]);

        [$web, $worker] = $this->transactions();
        self::assertSame(['GET /users/{user}', 'route', 'internal_error', '0af7651916cd43dd8448eb211c80319c'], [$web['transaction'], $web['transaction_info']['source'], $web['contexts']['trace']['status'], $web['contexts']['trace']['trace_id']]);
        self::assertSame([['db', 'select * from "users" where "id" = ? limit ?', 'postgresql', 'app']], array_map(static fn ($s) => [$s['op'], $s['description'], $s['data']['db.system'], $s['data']['db.name']], $web['spans']));
        self::assertArrayHasKey('sentry-trace', $headersForJobs['guidely']);
        self::assertSame(['App\Jobs\SendInvoice', 'queue.process', 'ok', $web['contexts']['trace']['trace_id']], [$worker['transaction'], $worker['contexts']['trace']['op'], $worker['contexts']['trace']['status'], $worker['contexts']['trace']['trace_id']]);
        self::assertSame(['messaging.system' => 'laravel', 'messaging.destination.name' => 'default', 'messaging.message.id' => 'j-1', 'messaging.message.retry.count' => 0, 'laravel.queue.connection' => 'redis'], $worker['contexts']['trace']['data']);
        self::assertSame(['update invoices set sent = ? where id = ?'], array_column($worker['spans'], 'description'));
        $error = $this->sent('exception')[0];
        self::assertSame(['laravel', false], [$error['properties']['mechanism'], $error['properties']['handled']]);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $error['detail']['contexts']['trace']['trace_id']);
    }
}

/** An immutable PSR-7-like request. */
final class FakePsr7Request
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly string $method, public readonly string $url, public array $headers = [])
    {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->url;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function withHeader(string $name, string $value): self
    {
        $c = clone $this;
        $c->headers[strtolower($name)] = $value;
        return $c;
    }
}

/** A settled promise with Guzzle's then() / wait() semantics (a handler that throws rejects). */
final class FakePromise
{
    private function __construct(private readonly bool $ok, private readonly mixed $value)
    {
    }

    public static function fulfilled(mixed $v): self
    {
        return new self(true, $v);
    }

    public static function rejected(mixed $reason): self
    {
        return new self(false, $reason);
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $fn = $this->ok ? $onFulfilled : $onRejected;
        if ($fn === null) {
            return $this;
        }
        try {
            $v = $fn($this->value);
            return $v instanceof self ? $v : self::fulfilled($v);
        } catch (\Throwable $e) {
            return self::rejected($e);
        }
    }

    public function wait(): mixed
    {
        if (!$this->ok) {
            throw $this->value instanceof \Throwable ? $this->value : new \RuntimeException((string) $this->value);
        }
        return $this->value;
    }
}

final class OwnStatement extends \PDOStatement
{
    protected function __construct()
    {
    }
}

final class FakeLaravelRequest
{
    public object $headers;

    public function __construct(private string $method, private string $uri, array $headers)
    {
        $this->headers = new class ($headers) {
            public function __construct(private array $h)
            {
            }

            public function all(): array
            {
                return $this->h;
            }
        };
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }
}

/** The parts of a Laravel application, event dispatcher, HTTP factory and exception handler the integration uses. */
final class FakeLaravelApp
{
    /** @var array<string, list<\Closure>> */
    public array $listeners = [];
    public object $http;
    public ?\Closure $reportable = null;
    public mixed $payloadHook = null;

    public function __construct()
    {
        $this->http = new class () {
            public int $middlewares = 0;

            public function globalMiddleware(callable $m): void
            {
                $this->middlewares++;
            }
        };
        $app = $this;
        // Queue::createPayloadUsing is static in Laravel; the test captures the hook through this stand-in.
        if (!class_exists('Illuminate\Queue\Queue', false)) {
            eval('namespace Illuminate\Queue; class Queue { public static $hook; public static function createPayloadUsing($cb) { self::$hook = $cb; } }');
        }
        $this->payloadHook = static fn (...$args) => (\Illuminate\Queue\Queue::$hook)(...$args);
    }

    public function make(string $abstract): object
    {
        $app = $this;
        return match ($abstract) {
            'events' => new class ($app) {
                public function __construct(private FakeLaravelApp $app)
                {
                }

                public function listen(string $event, \Closure $listener): void
                {
                    $this->app->listeners[$event][] = $listener;
                }
            },
            'Illuminate\Http\Client\Factory' => $this->http,
            'Illuminate\Contracts\Debug\ExceptionHandler' => new class ($app) {
                public function __construct(private FakeLaravelApp $app)
                {
                }

                public function reportable(\Closure $cb): void
                {
                    $this->app->reportable = $cb;
                }
            },
            'request' => new FakeLaravelRequest('GET', 'https://app.test/users/7?page=1', []),
        };
    }

    public function bound(string $abstract): bool
    {
        return in_array($abstract, ['Illuminate\Http\Client\Factory', 'Illuminate\Contracts\Debug\ExceptionHandler'], true);
    }

    public function runningInConsole(): bool
    {
        return true; // Octane workers and queue workers run on the CLI
    }

    public function terminating(\Closure $cb): void
    {
    }

    public function dispatch(string $event, object $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $l) {
            $l($payload);
        }
    }
}
