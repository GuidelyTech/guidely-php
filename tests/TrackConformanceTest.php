<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Exception\TransportException;
use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Track\GuidelyTrack;
use GuidelyTech\Track\Messages;
use GuidelyTech\Track\Retry;
use GuidelyTech\Track\TrackException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared Track Events vectors (sdks/conformance/track-vectors.json). */
final class TrackConformanceTest extends TestCase
{
    private static ?array $vectors = null;

    private static function vectors(): array
    {
        if (self::$vectors === null) {
            $raw = file_get_contents(__DIR__ . '/../../conformance/track-vectors.json');
            self::assertNotFalse($raw, 'track-vectors.json not found');
            self::$vectors = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        return self::$vectors;
    }

    /** @param list<TrackException> $errors */
    public static function client(FakeHttpClient $http, array &$errors, array $options = []): GuidelyTrack
    {
        return new GuidelyTrack(
            'gly_sk_test_123',
            ...array_merge([
                'environment' => 'production',
                'httpClient' => $http,
                'onError' => static function (\Throwable $e) use (&$errors): void {
                    $errors[] = $e;
                },
                'flushOnShutdown' => false,
                'sleep' => static function (int $ms): void {
                },
                'random' => static fn (): float => 0.5,
            ], $options),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function sent(FakeHttpClient $http): array
    {
        return array_map(static fn (array $r) => json_decode((string) $r['body'], true, 512, JSON_THROW_ON_ERROR), $http->requests);
    }

    /** @param list<TrackException> $errors */
    private static function droppedBy(array $errors, string $code): int
    {
        return array_sum(array_map(static fn (TrackException $e) => $e->errorCode === $code ? $e->count : 0, $errors));
    }

    private static function ksortDeep(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        ksort($v);
        return array_map(self::ksortDeep(...), $v);
    }

    public function testLimits(): void
    {
        $v = self::vectors();
        self::assertSame(1, $v['version']);
        self::assertSame([
            'maxBatchMessages' => Messages::MAX_BATCH_MESSAGES,
            'maxBatchBytes' => Messages::MAX_BATCH_BYTES,
            'maxMessageBytes' => Messages::MAX_MESSAGE_BYTES,
            'maxEventLength' => Messages::MAX_EVENT_LENGTH,
            'maxIdLength' => Messages::MAX_ID_LENGTH,
        ], $v['limits']);
        self::assertGreaterThan(50, count($v['messages']));
    }

    /** @return iterable<string, array{array}> */
    public static function messageCases(): iterable
    {
        foreach (self::vectors()['messages'] as $i => $c) {
            yield sprintf('#%d %s', $i, $c['name']) => [$c];
        }
    }

    #[DataProvider('messageCases')]
    public function testMessage(array $c): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
        $errors = [];
        $client = self::client($http, $errors);
        $client->{$c['call']}($c['args']);
        $client->flush();
        if ($c['expected'] === null) {
            self::assertCount(0, $http->requests);
            self::assertSame([TrackException::INVALID_MESSAGE], array_map(static fn (TrackException $e) => $e->errorCode, $errors));
            return;
        }
        self::assertSame([], array_map(static fn (\Throwable $e) => $e->getMessage(), $errors));
        self::assertCount(1, $http->requests);
        $message = self::sent($http)[0]['batch'][0];
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]{24}$/', $message['messageId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $message['timestamp']);
        unset($message['messageId'], $message['timestamp']);
        self::assertSame(self::ksortDeep($c['expected']), self::ksortDeep($message));
        // Empty traits / properties go out as JSON objects, not arrays.
        foreach (['traits', 'properties'] as $k) {
            if (($c['expected'][$k] ?? null) === []) {
                self::assertStringContainsString("\"$k\":{}", (string) $http->requests[0]['body']);
            }
        }
    }

    public function testTimestamps(): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
        $errors = [];
        $client = self::client($http, $errors);
        $expected = [];
        foreach (self::vectors()['timestamps'] as $t) {
            self::assertSame($t['expected'], Messages::formatTimestamp(Messages::toDateTime($t['input'])), $t['input']);
            $client->track(['userId' => 'u_1', 'event' => 'At', 'timestamp' => $t['input']]);
            $expected[] = $t['expected'];
        }
        $client->track(['userId' => 'u_1', 'event' => 'At', 'timestamp' => new \DateTimeImmutable('2026-10-26 12:00:00', new \DateTimeZone('Europe/Paris'))]);
        $expected[] = '2026-10-26T11:00:00.000Z';
        $client->flush();
        self::assertSame([], $errors);
        self::assertSame($expected, array_column(self::sent($http)[0]['batch'], 'timestamp'));
    }

    /** @return iterable<string, array{array}> */
    public static function responseCases(): iterable
    {
        foreach (self::vectors()['responses']['cases'] as $i => $c) {
            yield sprintf('#%d %d %s after attempt %d', $i, $c['status'], $c['retryAfter'] ?? '', $c['attempt']) => [$c];
        }
    }

    #[DataProvider('responseCases')]
    public function testResponse(array $c): void
    {
        $b = self::vectors()['responses']['backoff'];
        self::assertSame($c['expected']['action'], Retry::action($c['status']));
        if ($c['expected']['action'] === 'retry') {
            self::assertSame($c['expected']['delayMs'], Retry::delayMs($c['attempt'], $c['retryAfter'] ?? null, $b['baseMs'], $b['maxMs'], $b['retryAfterMaxMs']));
        }
    }

    /** @return iterable<string, array{array}> */
    public static function retryLimitCases(): iterable
    {
        foreach (self::vectors()['responses']['retryLimit'] as $c) {
            yield sprintf('maxRetries %d, status %d', $c['maxRetries'], $c['status']) => [$c];
        }
    }

    #[DataProvider('retryLimitCases')]
    public function testRetryLimit(array $c): void
    {
        $http = new FakeHttpClient(static fn () => $c['status'] === 0 ? new TransportException('Connection refused') : new HttpResponse($c['status'], '{}'));
        $errors = [];
        $client = self::client($http, $errors, ['maxRetries' => $c['maxRetries']]);
        $client->track(['userId' => 'u_1', 'event' => 'Retry']);
        $client->flush();
        self::assertCount($c['expectedAttempts'], $http->requests);
        self::assertCount(1, array_unique(array_map(static fn (array $b) => $b['batch'][0]['messageId'], self::sent($http))));
        $ok = $c['status'] >= 200 && $c['status'] < 300;
        self::assertSame($ok ? [] : [TrackException::DELIVERY_FAILED], array_map(static fn (TrackException $e) => $e->errorCode, $errors));
    }

    /** @return iterable<string, array{array}> */
    public static function batchingCases(): iterable
    {
        foreach (self::vectors()['batching']['cases'] as $c) {
            yield $c['name'] => [$c];
        }
    }

    #[DataProvider('batchingCases')]
    public function testBatching(array $c): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(202, '{}'));
        $errors = [];
        $client = self::client($http, $errors, ['flushAt' => $c['flushAt'], 'maxQueueSize' => $c['maxQueueSize']]);
        for ($i = 0; $i < $c['enqueue']; $i++) {
            $properties = $c['padding'] > 0 ? ['i' => $i, 'pad' => str_repeat('x', $c['padding'])] : ['i' => $i];
            $client->track(['userId' => "u_$i", 'event' => 'Batch Test', 'properties' => $properties]);
        }
        $client->flush();
        $bodies = self::sent($http);
        self::assertSame($c['expectedBatches'], array_map(static fn (array $b) => count($b['batch']), $bodies));
        if ($c['expectedFirst'] !== null) {
            self::assertSame($c['expectedFirst'], $bodies[0]['batch'][0]['properties']['i']);
        }
        self::assertSame($c['expectedDropped'], self::droppedBy($errors, TrackException::QUEUE_FULL));
        self::assertSame($c['expectedRejected'], self::droppedBy($errors, TrackException::MESSAGE_TOO_LARGE));
        if ($c['enqueue'] > 0 && $c['expectedRejected'] === 0) {
            $first = json_encode(['type' => 'track', 'messageId' => str_repeat('x', 24), 'timestamp' => '2026-10-26T10:00:00.000Z', 'userId' => 'u_0', 'event' => 'Batch Test', 'properties' => $c['padding'] > 0 ? ['i' => 0, 'pad' => str_repeat('x', $c['padding'])] : ['i' => 0]], Messages::JSON_FLAGS);
            self::assertSame($c['messageBytes'], strlen($first));
        }
        $sent = array_merge(...array_map(static fn (array $b) => array_map(static fn (array $m) => $m['properties']['i'], $b['batch']), $bodies ?: [['batch' => []]]));
        $sorted = $sent;
        sort($sorted);
        self::assertSame($sorted, $sent);
    }
}
