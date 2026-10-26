<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Cache\CacheInterface;
use GuidelyTech\Flags\Cache\FileCache;
use GuidelyTech\Flags\Cache\InMemoryCache;
use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Flags\Reason;
use GuidelyTech\Flags\ServerHealth;
use PHPUnit\Framework\TestCase;

/** The shared outage circuit: workers stop calling a Guidely API that keeps failing. */
final class OutageTest extends TestCase
{
    private const PK = 'pk_production_abc123';

    private float $time = 1_760_000_000.0;
    /** @var list<\Throwable> */
    private array $errors = [];
    /** Whether the fake API is up. */
    private bool $up = false;
    /** What a down API answers: null for a network error, else the response. */
    private ?HttpResponse $downResponse = null;

    private function payload(int $version = 1): array
    {
        return [
            'schemaVersion' => 1, 'projectId' => 'proj_1', 'environment' => 'production', 'version' => $version,
            'generatedAt' => '2026-10-19T12:00:00.000Z', 'salt' => 'salt', 'segments' => new \stdClass(),
            'flags' => [
                'banner' => ['key' => 'banner', 'type' => 'string', 'version' => 1, 'enabled' => true, 'offValue' => 'off', 'defaultValue' => "v$version"],
            ],
        ];
    }

    /** An API that serves the payload while `$up`, and fails as `$downResponse` says otherwise. */
    private function api(): FakeHttpClient
    {
        return new FakeHttpClient(function (array $request): HttpResponse|\Throwable {
            if (!$this->up) {
                return $this->downResponse ?? new \GuidelyTech\Flags\Exception\TransportException('Operation timed out');
            }
            if ($request['path'] === '/api/v1/sdk/flags/evaluate') {
                return new HttpResponse(200, '{"flags":{"banner":{"key":"banner","value":"remote","reason":"DEFAULT"}}}');
            }
            return $request['path'] === '/api/v1/sdk/flags' ? new HttpResponse(200, json_encode($this->payload())) : new HttpResponse(202, '{}');
        });
    }

    /** A new client, as a new request on another PHP worker would make. */
    private function worker(FakeHttpClient $http, CacheInterface $cache, array $options = []): GuidelyFlags
    {
        return new GuidelyFlags($options + [
            'sdkKey' => self::PK,
            'httpClient' => $http,
            'cache' => $cache,
            'clock' => fn () => $this->time,
            'env' => static fn (string $name): ?string => null,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e;
            },
            'reportUsage' => false,
            'reportExposures' => false,
        ]);
    }

    private function cache(): InMemoryCache
    {
        return new InMemoryCache(fn () => $this->time);
    }

    public function testRepeatedFailuresStopEveryWorkerFromFetching(): void
    {
        $dir = sys_get_temp_dir() . '/guidely-flags-outage-' . bin2hex(random_bytes(4));
        $http = $this->api();
        $worker = fn () => $this->worker($http, new FileCache($dir)); // what workers share by default

        // Three cold workers try, fail and serve the fallback.
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame('fb', $worker()->getString('banner', 'fb'));
            self::assertCount($i, $http->requests);
        }
        self::assertCount(3, $this->errors);

        // The circuit is open: other workers serve fallbacks without touching the network or onError.
        $a = $worker();
        $b = $worker();
        self::assertSame('fb', $a->getString('banner', 'fb'));
        self::assertSame('fb', $b->getString('banner', 'fb'));
        self::assertCount(3, $http->requests);
        self::assertCount(3, $this->errors);
        $details = $worker()->getDetails('banner', 'fb');
        self::assertSame(Reason::ERROR, $details->reason);
        self::assertStringContainsString('Guidely API unavailable (3 failed requests in a row); next attempt in', (string) $details->errorMessage);

        // The first cooldown is 5 s (±20%). After it one worker probes; while it fails the cooldown doubles.
        $this->time += 6.1;
        self::assertSame('fb', $worker()->getString('banner', 'fb'));
        self::assertCount(4, $http->requests);
        $this->time += 7.9; // < 10 s * 0.8
        self::assertSame('fb', $worker()->getString('banner', 'fb'));
        self::assertCount(4, $http->requests);
        $this->time += 4.2; // > 10 s * 1.2 after the probe

        // The API is back: the next probe succeeds and everyone fetches normally again.
        $this->up = true;
        self::assertSame('v1', $worker()->getString('banner', 'fb'));
        self::assertCount(5, $http->requests);
        self::assertSame('v1', $worker()->getString('banner', 'fb'));
        self::assertCount(5, $http->requests, 'the payload is cached again');

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testRecoveryResetsTheFailureCount(): void
    {
        $http = $this->api();
        $cache = $this->cache();
        for ($i = 0; $i < 3; $i++) {
            $this->worker($http, $cache)->getString('banner');
        }
        $this->worker($http, $cache)->getString('banner');
        self::assertCount(3, $http->requests, 'open');

        $this->time += 6.1;
        $this->up = true;
        self::assertSame('v1', $this->worker($http, $cache, ['cacheTtlSeconds' => 0])->getString('banner'));
        self::assertCount(4, $http->requests);

        // Down again: the count starts from zero, so two failures do not open the circuit.
        $this->up = false;
        $this->worker($http, $cache, ['cacheTtlSeconds' => 0])->getString('banner');
        $this->worker($http, $cache, ['cacheTtlSeconds' => 0])->getString('banner');
        self::assertCount(6, $http->requests);
        self::assertSame('v1', $this->worker($http, $cache, ['cacheTtlSeconds' => 0])->getString('banner'), 'stale payload');
        self::assertCount(7, $http->requests, 'the third failure still goes out');
        $this->worker($http, $cache, ['cacheTtlSeconds' => 0])->getString('banner');
        self::assertCount(7, $http->requests, 'and opens the circuit');
    }

    public function testAnyAnswerOtherThan429Or5xxCountsAsUp(): void
    {
        $http = $this->api();
        $cache = $this->cache();
        $this->worker($http, $cache)->getString('banner');
        $this->worker($http, $cache)->getString('banner');
        $this->downResponse = new HttpResponse(404, '{"error":{"code":"not_found"}}');
        $this->worker($http, $cache)->getString('banner');
        $this->downResponse = new HttpResponse(502, 'Bad Gateway');
        $this->worker($http, $cache)->getString('banner');
        $this->downResponse = new HttpResponse(429, '');
        $this->worker($http, $cache)->getString('banner');
        self::assertCount(5, $http->requests);
        $this->worker($http, $cache)->getString('banner');
        self::assertCount(6, $http->requests, 'the 404 reset the count: 502 and 429 are only two failures');
        $this->worker($http, $cache)->getString('banner');
        self::assertCount(6, $http->requests);
    }

    public function testRetryAfterOpensTheCircuitDirectly(): void
    {
        $http = $this->api();
        $cache = $this->cache();
        $worker = fn () => $this->worker($http, $cache, ['cacheTtlSeconds' => 0]);
        $this->downResponse = new HttpResponse(503, '', ['retry-after' => '120']);
        $worker()->getString('banner');
        self::assertCount(1, $http->requests, 'one answer is enough, no threshold');

        $this->time += 119;
        self::assertSame('fb', $worker()->getString('banner', 'fb'));
        self::assertCount(1, $http->requests);
        $this->time += 1.5;
        $worker()->getString('banner');
        self::assertCount(2, $http->requests);
        $this->time += 121;
        $this->up = true;
        self::assertSame('v1', $worker()->getString('banner'));
        self::assertCount(3, $http->requests);

        // An HTTP date, on a 429.
        $this->up = false;
        $this->downResponse = new HttpResponse(429, '', ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', (int) $this->time + 30)]);
        $worker()->getString('banner');
        self::assertCount(4, $http->requests);
        $this->time += 20;
        $worker()->getString('banner');
        self::assertCount(4, $http->requests);
        $this->time += 11;
        $worker()->getString('banner');
        self::assertCount(5, $http->requests);

        // A huge value is capped at an hour.
        $this->time += 7;
        $this->up = true;
        $worker()->getString('banner');
        $this->up = false;
        $this->downResponse = new HttpResponse(503, '', ['retry-after' => '999999']);
        $worker()->getString('banner');
        self::assertCount(7, $http->requests);
        $this->time += 3599;
        $worker()->getString('banner');
        self::assertCount(7, $http->requests);
        $this->time += 2;
        $worker()->getString('banner');
        self::assertCount(8, $http->requests);
    }

    public function testParseRetryAfter(): void
    {
        self::assertSame(30.0, ServerHealth::parseRetryAfter(' 30 ', 0));
        self::assertSame(60.0, ServerHealth::parseRetryAfter('Wed, 21 Oct 2015 07:29:00 GMT', 1445412480));
        self::assertSame(0.0, ServerHealth::parseRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT', 1445412540));
        self::assertNull(ServerHealth::parseRetryAfter(null, 0));
        self::assertNull(ServerHealth::parseRetryAfter('soon', 0));
        self::assertNull(ServerHealth::parseRetryAfter('-5', 0));
    }

    public function testStalePayloadKeepsServingWhileOpen(): void
    {
        $http = $this->api();
        $cache = $this->cache();
        $this->up = true;
        self::assertSame('v1', $this->worker($http, $cache)->getString('banner'));

        $this->up = false;
        $this->downResponse = new HttpResponse(503, '', ['retry-after' => '600']);
        $this->time += 61;
        self::assertSame('v1', $this->worker($http, $cache)->getString('banner'));
        self::assertCount(2, $http->requests);

        // Long past the payload's TTL, still inside Retry-After: stale, and no request.
        $this->time += 300;
        $flags = $this->worker($http, $cache);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertSame(Reason::DEFAULT, $flags->getDetails('banner')->reason);
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('next attempt in 300s', (string) $flags->getLastError());

        // refresh() is explicit and still goes out; its success closes the circuit for everyone.
        $this->up = true;
        self::assertTrue($flags->refresh());
        self::assertCount(3, $http->requests);
        $this->time += 61;
        self::assertSame('v1', $this->worker($http, $cache)->getString('banner'));
        self::assertCount(4, $http->requests);
    }

    public function testOneProcessDoesNotRetryAFailedServer(): void
    {
        // Remote evaluation: every attribute set is its own request.
        $http = $this->api();
        $flags = $this->worker($http, $this->cache(), ['remoteEval' => true, 'reportUsage' => true]);
        self::assertSame('a', $flags->getString('banner', 'a', ['id' => 'u1']));
        self::assertSame('b', $flags->getString('banner', 'b', ['id' => 'u2']));
        self::assertSame('c', $flags->getString('banner', 'c', ['id' => 'u3']));
        self::assertCount(1, $http->requests, 'one failure holds back the rest of the request');
        self::assertCount(1, $this->errors);

        // Not remembered as failed: after the hold, a long-running worker tries again.
        $this->time += 5.5;
        $this->up = true;
        self::assertSame('remote', $flags->getString('banner', 'b', ['id' => 'u2']));
        self::assertCount(2, $http->requests);
    }

    public function testRemoteEvaluationSharesTheCircuit(): void
    {
        $http = $this->api();
        $cache = $this->cache();
        for ($i = 1; $i <= 3; $i++) {
            $this->worker($http, $cache, ['remoteEval' => true])->getString('banner', null, ['id' => "u$i"]);
        }
        self::assertCount(3, $http->requests);
        $flags = $this->worker($http, $cache, ['remoteEval' => true]);
        self::assertSame('fb', $flags->getString('banner', 'fb', ['id' => 'u9']));
        self::assertSame([], $flags->getAll(['id' => 'u9']));
        self::assertCount(3, $http->requests);
        self::assertStringContainsString('Remote evaluation unavailable: Guidely API unavailable', (string) $flags->getDetails('banner', null, ['id' => 'u9'])->errorMessage);
    }

    public function testEventsAreNotSentWhileOpen(): void
    {
        $http = $this->api();
        $flags = $this->worker($http, $this->cache(), ['payload' => $this->payload(), 'reportUsage' => true]);
        $flags->getString('banner');
        $flags->flush();
        self::assertCount(1, $http->to('/api/v1/sdk/flags/events'));
        $flags->getString('banner');
        $flags->flush();
        self::assertCount(1, $http->requests, 'the shutdown flush does not wait on a server that just failed');
    }
}
