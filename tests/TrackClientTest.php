<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Exception\TransportException;
use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Track\GuidelyTrack;
use GuidelyTech\Track\Retry;
use GuidelyTech\Track\TrackException;
use PHPUnit\Framework\TestCase;

final class TrackClientTest extends TestCase
{
    /** @var list<TrackException> */
    private array $errors = [];
    /** @var list<int> */
    private array $sleeps = [];

    private function client(FakeHttpClient $http, array $options = []): GuidelyTrack
    {
        $errors = &$this->errors;
        $sleeps = &$this->sleeps;
        return new GuidelyTrack('gly_sk_test_123', ...array_merge([
            'environment' => 'production',
            'httpClient' => $http,
            'onError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e;
            },
            'flushOnShutdown' => false,
            'sleep' => static function (int $ms) use (&$sleeps): void {
                $sleeps[] = $ms;
            },
            'random' => static fn (): float => 0.5,
        ], $options));
    }

    private static function accepted(): FakeHttpClient
    {
        return new FakeHttpClient(static fn () => new HttpResponse(202, '{"accepted":1,"duplicates":0}'));
    }

    /** @return list<array<string, mixed>> */
    private static function bodies(FakeHttpClient $http): array
    {
        return array_map(static fn (array $r) => json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR), $http->requests);
    }

    private function codes(): array
    {
        return array_map(static fn (TrackException $e) => $e->errorCode, $this->errors);
    }

    public function testNeedsASecretKeyAndEnvironment(): void
    {
        foreach ([['pk_prod_1', 'production'], ['', 'production'], ['gly_sk_1', 'Production'], ['gly_sk_1', '']] as [$key, $env]) {
            try {
                new GuidelyTrack($key, $env, flushOnShutdown: false);
                self::fail("accepted $key / $env");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAcceptsCustomEnvironments(): void
    {
        $http = self::accepted();
        $client = $this->client($http, ['apiUrl' => 'https://guidely.example.com', 'environment' => 'qa-eu']);
        self::assertTrue($client->track(['userId' => 'u_42', 'event' => 'Signed Up']));
        self::assertTrue($client->flush());
        self::assertSame('https://guidely.example.com/api/v1/sdk/server/batch?environment=qa-eu', $http->requests[0]['url']);
    }

    public function testVersion(): void
    {
        self::assertSame('0.3.0', GuidelyTrack::VERSION);
        self::assertSame(GuidelyFlags::VERSION, GuidelyTrack::VERSION);
        self::assertSame('guidely-php', GuidelyTrack::SDK_NAME);
    }

    public function testPostsBatchesWithTheKeyEnvironmentAndSdk(): void
    {
        $http = self::accepted();
        $client = $this->client($http, ['apiUrl' => 'https://guidely.example.com/', 'environment' => 'staging']);
        self::assertTrue($client->identify(['userId' => 'u_42', 'traits' => ['plan' => 'pro'], 'accountId' => 'acme']));
        self::assertTrue($client->track(['userId' => 'u_42', 'event' => 'Report Exported', 'properties' => ['format' => 'csv']]));
        self::assertTrue($client->group(['accountId' => 'acme', 'traits' => ['name' => 'Acme Inc'], 'userId' => 'u_42']));
        self::assertSame(3, $client->queuedCount());
        self::assertTrue($client->flush());
        self::assertSame(0, $client->queuedCount());
        self::assertCount(1, $http->requests);
        $req = $http->requests[0];
        self::assertSame('POST', $req['method']);
        self::assertSame('https://guidely.example.com/api/v1/sdk/server/batch?environment=staging', $req['url']);
        self::assertSame('Bearer gly_sk_test_123', $req['headers']['Authorization']);
        self::assertSame('application/json', $req['headers']['Content-Type']);
        self::assertStringStartsWith('guidely-php/0.3.0 PHP/', $req['headers']['User-Agent']);
        $body = self::bodies($http)[0];
        self::assertSame(['name' => 'guidely-php', 'version' => '0.3.0'], $body['sdk']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $body['sentAt']);
        self::assertSame(['identify', 'track', 'group'], array_column($body['batch'], 'type'));
        self::assertCount(3, array_unique(array_column($body['batch'], 'messageId')));
        self::assertSame([], $this->errors);
    }

    public function testSendsWhenFlushAtMessagesAreQueued(): void
    {
        $http = self::accepted();
        $client = $this->client($http, ['flushAt' => 2]);
        $client->track(['userId' => 'u', 'event' => 'A']);
        self::assertCount(0, $http->requests);
        $client->track(['userId' => 'u', 'event' => 'B']);
        self::assertCount(1, $http->requests);
        self::assertSame(0, $client->queuedCount());
    }

    public function testBacksOffWithJitterAndHonoursRetryAfterCappedLow(): void
    {
        $answers = [new HttpResponse(503), new TransportException('reset'), new HttpResponse(429, '', ['retry-after' => '7']), new HttpResponse(202)];
        $http = new FakeHttpClient(static function () use (&$answers) {
            return array_shift($answers);
        });
        $client = $this->client($http);
        $client->track(['userId' => 'u', 'event' => 'E']);
        self::assertTrue($client->flush());
        self::assertCount(4, $http->requests);
        // 100 ms, 200 ms, then Retry-After 7 s capped at retryMaxDelayMs (1 s).
        self::assertSame([100, 200, 1000], $this->sleeps);
        self::assertCount(1, array_unique(array_map(static fn (array $b) => $b['batch'][0]['messageId'], self::bodies($http))));
        self::assertSame([], $this->errors);
        self::assertSame(80, Retry::jitter(100, 0.0));
        self::assertSame(120, Retry::jitter(100, 0.999999));
    }

    public function testDefaultRetriesStayShort(): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(503));
        $client = $this->client($http, ['random' => static fn (): float => 0.999999]);
        $client->track(['userId' => 'u', 'event' => 'E']);
        self::assertFalse($client->flush());
        self::assertCount(4, $http->requests); // 1 + maxRetries 3
        self::assertLessThanOrEqual(1000, array_sum($this->sleeps));
        self::assertSame([TrackException::DELIVERY_FAILED], $this->codes());
        self::assertSame(503, $this->errors[0]->status);
    }

    public function testDropsABatchOnARejectedKey(): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(403));
        $client = $this->client($http);
        $client->track(['userId' => 'u', 'event' => 'A']);
        $client->track(['userId' => 'u', 'event' => 'B']);
        self::assertFalse($client->flush());
        self::assertCount(1, $http->requests);
        self::assertSame([TrackException::DELIVERY_FAILED], $this->codes());
        self::assertSame(2, $this->errors[0]->count);
        self::assertSame(403, $this->errors[0]->status);
        self::assertStringContainsString('events:write', $this->errors[0]->getMessage());
    }

    public function testNeverThrows(): void
    {
        $http = self::accepted();
        $client = new GuidelyTrack('gly_sk_x', 'development', httpClient: $http, flushOnShutdown: false, onError: static function (): void {
            throw new \RuntimeException('handler boom');
        });
        self::assertFalse($client->track([]));
        self::assertFalse($client->track(['userId' => 'u', 'event' => str_repeat('x', 121)]));
        self::assertFalse($client->identify(['userId' => ['nested']]));
        self::assertFalse($client->group(['accountId' => 'acme', 'traits' => ['bad' => NAN]]));
        self::assertFalse($client->track(['userId' => 'u', 'event' => 'E', 'timestamp' => 'yesterday']));
        self::assertTrue($client->flush());
        self::assertCount(0, $http->requests);

        $down = new FakeHttpClient(static fn () => new \LogicException('custom client bug'));
        $client = $this->client($down, ['maxRetries' => 0]);
        $client->track(['userId' => 'u', 'event' => 'E']);
        self::assertFalse($client->flush());
        self::assertSame(0, $this->errors[0]->status);
    }

    public function testStdClassAndInvalidUtf8(): void
    {
        $http = self::accepted();
        $client = $this->client($http);
        $client->track(['userId' => 'u', 'event' => 'E', 'properties' => (object) ['a' => 1]]);
        $client->track(['userId' => 'u', 'event' => 'E', 'properties' => ['name' => "bad \xff byte"]]);
        $client->flush();
        $batch = self::bodies($http)[0]['batch'];
        self::assertSame(['a' => 1], $batch[0]['properties']);
        self::assertSame("bad \u{FFFD} byte", $batch[1]['properties']['name']);
    }

    public function testCloseFlushesAndDropsLaterCalls(): void
    {
        $http = self::accepted();
        $client = $this->client($http);
        $client->track(['userId' => 'u', 'event' => 'E']);
        $client->close();
        self::assertCount(1, $http->requests);
        self::assertFalse($client->track(['userId' => 'u', 'event' => 'Late']));
        $client->flush();
        self::assertCount(1, $http->requests);
        self::assertSame([TrackException::CLOSED], $this->codes());
    }

    public function testDestructorFlushes(): void
    {
        $http = self::accepted();
        $client = $this->client($http);
        $client->track(['userId' => 'u', 'event' => 'E']);
        unset($client);
        self::assertCount(1, $http->requests);
    }

    public function testShutdownFunctionDoesNotKeepTheClientAlive(): void
    {
        $http = self::accepted();
        $client = new GuidelyTrack('gly_sk_x', 'production', httpClient: $http, flushOnShutdown: true);
        $client->track(['userId' => 'u', 'event' => 'E']);
        $ref = \WeakReference::create($client);
        unset($client);
        self::assertNull($ref->get());
        self::assertCount(1, $http->requests); // flushed by the destructor, exactly once
    }

    public function testFlushFromTheErrorHandlerDoesNotRecurse(): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(400));
        $client = null;
        $calls = 0;
        $client = new GuidelyTrack('gly_sk_x', 'production', httpClient: $http, flushOnShutdown: false, onError: static function () use (&$client, &$calls): void {
            $calls++;
            $client?->flush();
        });
        $client->track(['userId' => 'u', 'event' => 'A']);
        $client->track(['userId' => 'u', 'event' => 'B']);
        $client->flush();
        self::assertSame(1, $calls);
        self::assertCount(1, $http->requests);
    }
}
