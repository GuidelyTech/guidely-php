<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Cache\FileCache;
use GuidelyTech\Flags\Cache\InMemoryCache;
use GuidelyTech\Flags\EvaluationResult;
use GuidelyTech\Flags\Exception\DecryptionException;
use GuidelyTech\Flags\Exception\RemoteEvaluationRequiredException;
use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Http\HttpResponse;
use GuidelyTech\Flags\Options;
use GuidelyTech\Flags\OverrideBehaviour;
use GuidelyTech\Flags\Reason;
use GuidelyTech\Flags\Sticky\InMemoryStickyBucketService;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const PK = 'pk_production_abc123';
    private const SK = 'gly_sk_secret123';

    private float $time = 1_760_000_000.0;
    /** @var list<\Throwable> */
    private array $errors = [];

    private function payload(int $version = 1): array
    {
        return [
            'schemaVersion' => 1,
            'projectId' => 'proj_1',
            'environment' => 'production',
            'version' => $version,
            'generatedAt' => '2026-10-19T12:00:00.000Z',
            'salt' => 'salt',
            'flags' => [
                'new-checkout' => ['key' => 'new-checkout', 'type' => 'boolean', 'version' => 1, 'enabled' => true, 'offValue' => false, 'defaultValue' => false, 'rules' => [
                    ['id' => 'r1', 'type' => 'force', 'value' => true, 'variationId' => 'on', 'conditions' => [
                        ['kind' => 'attribute', 'attribute' => 'plan', 'operator' => 'equals', 'values' => ['pro']],
                    ]],
                ]],
                'banner' => ['key' => 'banner', 'type' => 'string', 'version' => 1, 'enabled' => true, 'offValue' => 'off', 'defaultValue' => "v$version"],
                'limit' => ['key' => 'limit', 'type' => 'number', 'version' => 1, 'enabled' => true, 'offValue' => 0, 'defaultValue' => 10],
                'config' => ['key' => 'config', 'type' => 'json', 'version' => 1, 'enabled' => true, 'offValue' => null, 'defaultValue' => ['theme' => 'dark', 'items' => [1, 2]]],
                'checkout-test' => ['key' => 'checkout-test', 'type' => 'string', 'version' => 1, 'enabled' => true, 'offValue' => 'control', 'defaultValue' => 'control', 'rules' => [
                    ['id' => 'exp', 'type' => 'experiment', 'key' => 'checkout-exp', 'hashAttribute' => 'id', 'seed' => 'checkout-exp', 'coverage' => 1,
                        'variations' => [['id' => 'a', 'value' => 'A', 'weight' => 0.5], ['id' => 'b', 'value' => 'B', 'weight' => 0.5]]],
                ]],
            ],
            'segments' => new \stdClass(),
        ];
    }

    private function client(array $options): GuidelyFlags
    {
        return new GuidelyFlags($options + [
            'sdkKey' => self::PK,
            'cache' => new InMemoryCache(fn () => $this->time),
            'clock' => fn () => $this->time,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e;
            },
            'reportUsage' => false,
            'reportExposures' => false,
        ]);
    }

    public function testPublicKeyTransportAndEvaluation(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['httpClient' => $http, 'apiUrl' => 'https://flags.example.com/']);

        self::assertTrue($flags->isEnabled('new-checkout', ['plan' => 'pro']));
        self::assertFalse($flags->isEnabled('new-checkout', ['plan' => 'free']));
        self::assertSame('v1', $flags->getString('banner'));
        self::assertSame(10, $flags->getNumber('limit'));
        self::assertSame(['theme' => 'dark', 'items' => [1, 2]], $flags->getJson('config'));

        self::assertCount(1, $http->requests, 'payload is fetched once');
        $request = $http->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertStringStartsWith('https://flags.example.com/api/v1/sdk/flags?', $request['url']);
        self::assertSame(['key' => self::PK], $request['query']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertSame([], $this->errors);
    }

    public function testSecretKeyTransport(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['sdkKey' => self::SK, 'environment' => 'staging', 'httpClient' => $http]);

        self::assertSame('v1', $flags->getString('banner'));
        self::assertSame('Bearer ' . self::SK, $http->requests[0]['headers']['Authorization']);
        self::assertSame(['environment' => 'staging'], $http->requests[0]['query']);
    }

    public function testSecretKeyRequiresEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GuidelyFlags(['sdkKey' => self::SK]);
    }

    public function testOptionsValidation(): void
    {
        $options = Options::fromArray(['projectId' => self::PK, 'overrideBehaviour' => 'remoteOverLocal']);
        self::assertSame(self::PK, $options->sdkKey);
        self::assertSame(OverrideBehaviour::RemoteOverLocal, $options->overrideBehaviour);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cacheTTL');
        Options::fromArray(['sdkKey' => self::PK, 'cacheTTL' => 5]);
    }

    public function testCacheTtlAndEtagRevalidation(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $cache = new InMemoryCache(fn () => $this->time);
        $make = fn () => $this->client(['httpClient' => $http, 'cache' => $cache, 'cacheTtlSeconds' => 60]);

        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(1, $http->requests);

        // Another "request" (new client, same cache) within the TTL: no network.
        $this->time += 30;
        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(1, $http->requests);

        // After the TTL: revalidated with If-None-Match, 304 keeps the cached payload.
        $this->time += 31;
        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(2, $http->requests);
        self::assertSame('"v1"', $http->requests[1]['headers']['If-None-Match']);

        // The 304 refreshed the cache timestamp.
        $this->time += 30;
        self::assertSame('v1', $make()->getString('banner'));
        self::assertCount(2, $http->requests);

        // A change on the server is picked up after the TTL.
        $payload = $this->payload(2);
        $this->time += 31;
        self::assertSame('v2', $make()->getString('banner'));
        self::assertCount(3, $http->requests);

        // A long-lived client revalidates by itself once its TTL passes.
        $client = $make();
        self::assertSame('v2', $client->getString('banner'));
        $payload = $this->payload(3);
        $this->time += 61;
        self::assertSame('v3', $client->getString('banner'));
        self::assertSame([], $this->errors);
    }

    public function testRefreshForcesAFetch(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['httpClient' => $http]);
        self::assertSame('v1', $flags->getString('banner'));
        $payload = $this->payload(2);
        self::assertTrue($flags->refresh());
        self::assertSame('v2', $flags->getString('banner'));
        self::assertCount(2, $http->requests);
        self::assertSame(2, $flags->getPayload()['version']);
    }

    public function testServesStalePayloadWhenTheApiFails(): void
    {
        $payload = $this->payload();
        $cache = new InMemoryCache(fn () => $this->time);
        $this->client(['httpClient' => FakeHttpClient::serving($payload), 'cache' => $cache])->getString('banner');

        $this->time += 120;
        $down = FakeHttpClient::down();
        $flags = $this->client(['httpClient' => $down, 'cache' => $cache]);
        self::assertSame('v1', $flags->getString('banner'), 'stale payload keeps serving');
        self::assertCount(1, $down->requests);
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('Connection refused', $this->errors[0]->getMessage());

        // Other workers do not hammer the API: the stale entry counts as fresh for another TTL.
        $this->client(['httpClient' => $down, 'cache' => $cache])->getString('banner');
        self::assertCount(1, $down->requests);
    }

    public function testFallbacksWhenNothingCanBeLoaded(): void
    {
        $down = FakeHttpClient::down();
        $flags = $this->client(['httpClient' => $down]);

        self::assertTrue($flags->isEnabled('new-checkout', ['plan' => 'pro'], true));
        self::assertSame('fallback', $flags->getString('banner', 'fallback'));
        self::assertSame(5, $flags->getNumber('limit', 5));
        $details = $flags->getDetails('banner', 'fb');
        self::assertSame(Reason::ERROR, $details->reason);
        self::assertSame('fb', $details->value);
        self::assertStringContainsString('Connection refused', (string) $details->errorMessage);
        self::assertCount(1, $down->requests, 'a failed load is not retried on every read');
    }

    public function testTypedGettersReturnTheFallbackForTheWrongType(): void
    {
        $flags = $this->client(['payload' => $this->payload()]);
        self::assertSame('x', $flags->getString('limit', 'x'));
        self::assertSame(1.5, $flags->getNumber('banner', 1.5));
        self::assertTrue($flags->getBool('banner', true));
        self::assertTrue($flags->isEnabled('banner', [], true));
        self::assertSame(['d' => 1], $flags->getJson('banner', ['d' => 1]));
        self::assertSame('dflt', $flags->getValue('missing', 'dflt'));
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('missing')->reason);
    }

    public function testBootstrapPayloadDoesNotFetch(): void
    {
        $http = FakeHttpClient::down();
        $flags = $this->client(['payload' => json_encode($this->payload()), 'httpClient' => $http]);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertTrue($flags->isEnabled('new-checkout', ['plan' => 'pro']));
        self::assertSame([], $http->requests);
    }

    public function testOverridesLocalOverRemote(): void
    {
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload), 'overrides' => ['banner' => 'forced', 'local-only' => 42]]);
        $details = $flags->getDetails('banner');
        self::assertSame('forced', $details->value);
        self::assertSame(Reason::OVERRIDE, $details->reason);
        self::assertSame(42, $flags->getNumber('local-only'));
        self::assertSame(10, $flags->getNumber('limit'));
    }

    public function testOverridesRemoteOverLocal(): void
    {
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload), 'overrides' => ['banner' => 'forced', 'local-only' => 42], 'overrideBehaviour' => 'remoteOverLocal']);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertSame(42, $flags->getNumber('local-only'));
        self::assertSame(Reason::OVERRIDE, $flags->getDetails('local-only')->reason);

        $all = $flags->getAll();
        self::assertSame('v1', $all['banner']->value);
        self::assertSame(42, $all['local-only']->value);
    }

    public function testOverridesLocalOnlyNeverFetches(): void
    {
        $http = FakeHttpClient::down();
        $flags = $this->client(['httpClient' => $http, 'overrides' => ['banner' => 'local'], 'overrideBehaviour' => OverrideBehaviour::LocalOnly]);
        self::assertSame('local', $flags->getString('banner'));
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('limit')->reason);
        self::assertSame(7, $flags->getNumber('limit', 7));

        $flags->setOverrides(['limit' => 3]);
        self::assertSame(3, $flags->getNumber('limit'));
        self::assertNull($flags->getString('banner'));
        $flags->flush();
        self::assertSame([], $http->requests);
        self::assertSame([], $this->errors);
    }

    private static function encrypt(array $inner, string $key): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt(json_encode($inner), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return base64_encode($iv) . '.' . base64_encode($ciphertext . $tag);
    }

    private function encryptedEnvelope(string $key, int $version = 1): array
    {
        $plain = $this->payload($version);
        return [
            'schemaVersion' => 1, 'projectId' => 'proj_1', 'environment' => 'production', 'version' => $version, 'generatedAt' => $plain['generatedAt'],
            'encryptedPayload' => self::encrypt(['flags' => $plain['flags'], 'segments' => $plain['segments'], 'salt' => $plain['salt']], $key),
        ];
    }

    public function testEncryptedPayloadRoundTrip(): void
    {
        $key = random_bytes(32);
        $envelope = $this->encryptedEnvelope($key);
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($envelope), 'decryptionKey' => base64_encode($key)]);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertTrue($flags->isEnabled('new-checkout', ['plan' => 'pro']));
        self::assertSame('production', $flags->getPayload()['environment']);
        self::assertArrayNotHasKey('encryptedPayload', $flags->getPayload());
        self::assertSame([], $this->errors);
    }

    public function testEncryptedPayloadWithoutOrWithAWrongKey(): void
    {
        $key = random_bytes(32);
        $envelope = $this->encryptedEnvelope($key);

        $flags = $this->client(['httpClient' => FakeHttpClient::serving($envelope)]);
        self::assertSame('fb', $flags->getString('banner', 'fb'));
        self::assertInstanceOf(DecryptionException::class, $this->errors[0]);
        self::assertStringContainsString('decryptionKey', $this->errors[0]->getMessage());

        // A wrong key keeps the previous payload.
        $this->errors = [];
        $cache = new InMemoryCache(fn () => $this->time);
        $good = $this->client(['httpClient' => FakeHttpClient::serving($envelope), 'cache' => $cache, 'decryptionKey' => base64_encode($key)]);
        self::assertSame('v1', $good->getString('banner'));
        $rotated = $this->encryptedEnvelope(random_bytes(32), 2);
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($rotated), 'cache' => $cache, 'decryptionKey' => base64_encode($key)]);
        self::assertTrue($flags->refresh() === false);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertInstanceOf(DecryptionException::class, $this->errors[0]);
    }

    public function testRemoteEvaluationRequiredError(): void
    {
        $http = new FakeHttpClient(static fn () => new HttpResponse(403, '{"error":{"code":"remote_evaluation_required","message":"Use remote evaluation"}}'));
        $flags = $this->client(['httpClient' => $http]);
        self::assertSame('fb', $flags->getString('banner', 'fb'));
        self::assertInstanceOf(RemoteEvaluationRequiredException::class, $this->errors[0]);
        self::assertStringContainsString('gly_sk_', $this->errors[0]->getMessage());
        self::assertStringContainsString('remoteEval', $this->errors[0]->getMessage());
    }

    public function testRemoteEvaluation(): void
    {
        $http = new FakeHttpClient(static function (array $request): HttpResponse {
            $body = json_decode((string) $request['body'], true);
            $plan = $body['attributes']['plan'] ?? null;
            return new HttpResponse(200, json_encode(['version' => 3, 'flags' => [
                'new-checkout' => ['key' => 'new-checkout', 'value' => $plan === 'pro', 'reason' => $plan === 'pro' ? 'RULE_MATCH' : 'DEFAULT', 'ruleId' => 'r1', 'inExperiment' => false],
                'checkout-test' => ['key' => 'checkout-test', 'value' => 'B', 'reason' => 'EXPERIMENT', 'variationId' => 'b', 'inExperiment' => true,
                    'experiment' => ['key' => 'checkout-exp', 'variationId' => 'b', 'variationIndex' => 1, 'hashAttribute' => 'id', 'hashValue' => 'u1', 'bucket' => 0.7, 'stickyBucketUsed' => false]],
            ]]));
        });
        $tracked = [];
        $flags = $this->client([
            'httpClient' => $http, 'remoteEval' => true, 'defaultAttributes' => ['app' => 'web'],
            'trackingCallback' => function (array $experiment, EvaluationResult $result) use (&$tracked): void {
                $tracked[] = [$experiment['key'], $result->variationId];
            },
        ]);

        self::assertTrue($flags->isEnabled('new-checkout', ['plan' => 'pro', 'id' => 'u1']));
        self::assertSame('B', $flags->getString('checkout-test', null, ['plan' => 'pro', 'id' => 'u1']));
        self::assertCount(1, $http->requests, 'results are cached per attribute set');
        self::assertFalse($flags->isEnabled('new-checkout', ['plan' => 'free']));
        self::assertCount(2, $http->requests);
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('missing', null, ['plan' => 'free'])->reason);
        self::assertCount(2, $http->requests);

        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('/api/v1/sdk/flags/evaluate', $request['path']);
        self::assertSame(['attributes' => ['app' => 'web', 'plan' => 'pro', 'id' => 'u1']], json_decode((string) $request['body'], true));
        self::assertSame([['checkout-exp', 'b']], $tracked);

        // An empty attribute set is sent as a JSON object.
        $flags->getAll();
        self::assertSame('{"attributes":{"app":"web"}}', $http->requests[2]['body']);
    }

    public function testUsageAndExposureBatching(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $tracked = [];
        $flags = $this->client([
            'httpClient' => $http, 'reportUsage' => true, 'reportExposures' => true,
            'trackingCallback' => function (array $experiment, EvaluationResult $result) use (&$tracked): void {
                $tracked[] = $experiment + ['flag' => $result->key];
            },
        ]);

        $flags->isEnabled('new-checkout', ['plan' => 'pro']);
        $flags->isEnabled('new-checkout', ['plan' => 'pro']);
        $flags->isEnabled('new-checkout', ['plan' => 'free']);
        $flags->getValue('missing');
        $a = $flags->getString('checkout-test', null, ['id' => 'user-1']);
        $flags->getString('checkout-test', null, ['id' => 'user-1']);
        self::assertCount(1, $tracked, 'tracking callback fires once per experiment/user/variation');
        self::assertSame('checkout-exp', $tracked[0]['key']);
        self::assertSame('user-1', $tracked[0]['hashValue']);
        self::assertSame([], $http->to('/api/v1/sdk/flags/events'), 'nothing is sent before flush');

        $flags->flush();
        $events = $http->to('/api/v1/sdk/flags/events');
        self::assertCount(1, $events);
        self::assertSame('POST', $events[0]['method']);
        self::assertSame(['key' => self::PK], $events[0]['query']);
        $batch = json_decode((string) $events[0]['body'], true);
        self::assertSame(['name' => 'guidely-php', 'version' => GuidelyFlags::VERSION], $batch['sdk']);
        $usage = [];
        foreach ($batch['usage'] as $u) {
            $usage[$u['flag'] . '|' . ($u['variationId'] ?? '') . '|' . $u['value']] = $u['count'];
        }
        self::assertSame(['new-checkout|on|true' => 2, 'new-checkout||false' => 1, 'checkout-test|' . ($a === 'A' ? 'a' : 'b') . '|"' . $a . '"' => 2], $usage);
        self::assertCount(1, $batch['exposures']);
        $exposure = $batch['exposures'][0];
        self::assertSame('checkout-exp', $exposure['experiment']);
        self::assertSame('checkout-test', $exposure['flag']);
        self::assertSame('id', $exposure['hashAttribute']);
        self::assertSame('user-1', $exposure['hashValue']);
        self::assertSame('user-1', $exposure['userId']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', (int) $this->time), $exposure['timestamp']);

        // Flushing again with nothing queued sends nothing.
        $flags->flush();
        self::assertCount(1, $http->to('/api/v1/sdk/flags/events'));

        // Activity after 60 s flushes automatically.
        $flags->getValue('banner');
        $this->time += 61;
        $flags->getValue('banner');
        self::assertCount(2, $http->to('/api/v1/sdk/flags/events'));
        self::assertSame([], $this->errors);
    }

    public function testUsageIsFlushedWhenTheClientIsDestroyed(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['httpClient' => $http, 'reportUsage' => true]);
        $flags->getValue('banner');
        unset($flags);
        self::assertCount(1, $http->to('/api/v1/sdk/flags/events'));
    }

    public function testLongValuesAreTruncatedInUsage(): void
    {
        $payload = $this->payload();
        $payload['flags']['banner']['defaultValue'] = str_repeat('é', 300);
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['httpClient' => $http, 'reportUsage' => true]);
        $flags->getValue('banner');
        $flags->flush();
        $batch = json_decode((string) $http->to('/api/v1/sdk/flags/events')[0]['body'], true);
        self::assertSame(200, mb_strlen($batch['usage'][0]['value']));
    }

    public function testForUserBindsAttributes(): void
    {
        $flags = $this->client(['payload' => $this->payload(), 'defaultAttributes' => ['plan' => 'pro', 'country' => 'GB']]);
        $user = $flags->forUser(['id' => 'u1']);
        self::assertTrue($user->isEnabled('new-checkout'));
        self::assertFalse($flags->forUser(['plan' => 'free'])->isEnabled('new-checkout'));
        self::assertFalse($user->with(['plan' => 'free'])->isEnabled('new-checkout'));
        self::assertSame('v1', $user->getString('banner'));
        self::assertSame(10, $user->getNumber('limit'));
        self::assertSame('on', $user->getDetails('new-checkout')->variationId);
        self::assertArrayHasKey('checkout-test', $user->getAll());
        self::assertSame(['id' => 'u1'], $user->getAttributes());
    }

    public function testStickyBucketingThroughTheClient(): void
    {
        $sticky = new InMemoryStickyBucketService(['id||u1' => ['checkout-exp__0' => 'b']]);
        $flags = $this->client(['payload' => $this->payload(), 'stickyBucketService' => $sticky]);
        $details = $flags->getDetails('checkout-test', null, ['id' => 'u1']);
        self::assertSame('B', $details->value);
        self::assertTrue($details->experiment['stickyBucketUsed']);

        $flags->getDetails('checkout-test', null, ['id' => 'u2']);
        self::assertArrayHasKey('id||u2', $sticky->all());
    }

    public function testCallbacksThatThrowDoNotBreakGetters(): void
    {
        $flags = $this->client([
            'payload' => $this->payload(),
            'onFlagEvaluated' => static function (): void {
                throw new \RuntimeException('boom');
            },
        ]);
        self::assertSame('v1', $flags->getString('banner'));
        self::assertStringContainsString('boom', $this->errors[0]->getMessage());
    }

    public function testOfflineServesTheCachedPayload(): void
    {
        $payload = $this->payload();
        $cache = new InMemoryCache(fn () => $this->time);
        $this->client(['httpClient' => FakeHttpClient::serving($payload), 'cache' => $cache])->getString('banner');

        $this->time += 3600;
        $http = FakeHttpClient::down();
        $flags = $this->client(['httpClient' => $http, 'cache' => $cache, 'offline' => true, 'reportUsage' => true]);
        self::assertSame('v1', $flags->getString('banner'));
        $flags->flush();
        self::assertSame([], $http->requests);
    }

    public function testFileCacheRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/guidely-flags-test-' . bin2hex(random_bytes(4));
        $cache = new FileCache($dir);
        self::assertNull($cache->get('k'));
        $cache->set('k', ['body' => '{"a":1}', 'etag' => '"x"', 'fetchedAt' => 1.5]);
        self::assertSame(['body' => '{"a":1}', 'etag' => '"x"', 'fetchedAt' => 1.5], $cache->get('k'));
        $cache->set('expired', 'v', -1);
        self::assertNull($cache->get('expired'));
        self::assertSame([], glob($dir . '/tmp-*'), 'no temporary files are left behind');
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);

        // The client works with the default file cache.
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $make = fn () => $this->client(['httpClient' => $http, 'cache' => new FileCache($dir), 'sdkKey' => 'pk_' . bin2hex(random_bytes(4))]);
        self::assertSame('v1', $make()->getString('banner'));
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    public function testPsr16CachesAreAcceptedByDuckTyping(): void
    {
        $psr16 = new class () {
            public array $data = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->data[$key] = $value;
                return true;
            }
        };
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        self::assertSame('v1', $this->client(['httpClient' => $http, 'cache' => $psr16])->getString('banner'));
        self::assertCount(1, $psr16->data);
        self::assertSame('v1', $this->client(['httpClient' => $http, 'cache' => $psr16])->getString('banner'));
        self::assertCount(1, $http->requests);
    }

    public function testLoggerReceivesErrors(): void
    {
        $logger = new class () {
            public array $messages = [];

            public function error(string $message, array $context = []): void
            {
                $this->messages[] = $message;
            }
        };
        $flags = new GuidelyFlags(['sdkKey' => self::PK, 'httpClient' => FakeHttpClient::down(), 'cache' => false, 'logger' => $logger, 'reportUsage' => false]);
        self::assertFalse($flags->isEnabled('x'));
        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('Connection refused', $logger->messages[0]);
    }
}
