<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Cache\ApcuCache;
use GuidelyTech\Flags\GuidelyFlags;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real ext-apcu. Skipped where APCu is missing or disabled on the CLI; the image in
 * sdks/php/docker enables it (see README → Development).
 */
final class ApcuCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('ext-apcu is not enabled on the CLI (use the sdks/php/docker image)');
        }
        apcu_clear_cache();
    }

    public function testRoundTripsPayloadEntries(): void
    {
        $cache = new ApcuCache();
        self::assertNull($cache->get('missing'));

        $entry = ['body' => '{"flags":{}}', 'etag' => '"v1"', 'fetchedAt' => 1760000000.5];
        $cache->set('k', $entry);
        self::assertSame($entry, $cache->get('k'));

        $cache->set('k', ['body' => 'replaced']);
        self::assertSame(['body' => 'replaced'], $cache->get('k'));
    }

    public function testKeepsFalsyValuesApartFromMisses(): void
    {
        $cache = new ApcuCache();
        $cache->set('false', false);
        $cache->set('zero', 0);
        self::assertFalse($cache->get('false'));
        self::assertSame(0, $cache->get('zero'));
    }

    public function testPrefixesIsolateCaches(): void
    {
        $a = new ApcuCache('app-a:');
        $b = new ApcuCache('app-b:');
        $a->set('payload', 'a');
        $b->set('payload', 'b');
        self::assertSame('a', $a->get('payload'));
        self::assertSame('b', $b->get('payload'));
        self::assertSame('a', apcu_fetch('app-a:payload'));
    }

    public function testEntriesExpireAfterTheirTtl(): void
    {
        $cache = new ApcuCache();
        $cache->set('short', 'x', 1);
        $cache->set('forever', 'y');
        self::assertSame('x', $cache->get('short'));
        sleep(2);
        self::assertNull($cache->get('short'));
        self::assertSame('y', $cache->get('forever'));
    }

    public function testClientsShareThePayloadThroughApcu(): void
    {
        $payload = [
            'schemaVersion' => 1, 'projectId' => 'p', 'environment' => 'production', 'version' => 1,
            'generatedAt' => '2026-10-19T12:00:00.000Z', 'salt' => 's', 'segments' => new \stdClass(),
            'flags' => ['banner' => ['key' => 'banner', 'type' => 'string', 'version' => 1, 'enabled' => true, 'offValue' => 'off', 'defaultValue' => 'v1']],
        ];
        $http = FakeHttpClient::serving($payload);
        $time = 1_760_000_000.0;
        // Defined outside the arrow function, which would otherwise capture $time by value.
        $clock = function () use (&$time): float {
            return $time;
        };
        $make = fn () => new GuidelyFlags([
            'sdkKey' => 'pk_production_abc', 'httpClient' => $http, 'cache' => new ApcuCache(),
            'cacheTtlSeconds' => 60, 'clock' => $clock, 'reportUsage' => false, 'reportExposures' => false,
        ]);

        // First "request" downloads; later requests in other workers read APCu without the network.
        self::assertSame('v1', $make()->getString('banner'));
        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(1, $http->requests);

        // Past the TTL the next worker revalidates; a 304 keeps the shared entry.
        $time += 61;
        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(2, $http->requests);
        self::assertSame('"v1"', $http->requests[1]['headers']['If-None-Match']);

        // A server change reaches every worker through the shared entry.
        $payload['version'] = 2;
        $payload['flags']['banner']['defaultValue'] = 'v2';
        $time += 61;
        self::assertSame('v2', $make()->getString('banner'));
        self::assertSame('v2', $make()->getString('banner'));
        self::assertCount(3, $http->requests);
    }
}
