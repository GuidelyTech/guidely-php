<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\Cache\InMemoryCache;
use GuidelyTech\Flags\DevOverrides;
use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\OverrideBehaviour;
use GuidelyTech\Flags\Reason;
use PHPUnit\Framework\TestCase;

/** Dev overrides from environment variables and files (docs/feature-flags-spec.md §4.1). */
final class DevOverridesTest extends TestCase
{
    private const PK = 'pk_production_abc123';

    private float $time = 1_760_000_000.0;
    /** @var array<string, string> the fake process environment */
    private array $env = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var list<array{string, string}> level, message */
    private array $logs = [];
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    private function payload(): array
    {
        return [
            'schemaVersion' => 1, 'projectId' => 'proj_1', 'environment' => 'production', 'version' => 1,
            'generatedAt' => '2026-10-19T12:00:00.000Z', 'salt' => 'salt', 'segments' => new \stdClass(),
            'flags' => [
                'banner' => ['key' => 'banner', 'type' => 'string', 'version' => 1, 'enabled' => true, 'offValue' => 'off', 'defaultValue' => 'remote'],
                'limit' => ['key' => 'limit', 'type' => 'number', 'version' => 1, 'enabled' => true, 'offValue' => 0, 'defaultValue' => 10],
            ],
        ];
    }

    private function client(array $options = []): GuidelyFlags
    {
        $logger = new class (function (string $level, string $message): void {
            $this->logs[] = [$level, $message];
        }) {
            public function __construct(private \Closure $log)
            {
            }

            public function warning(string $message, array $context = []): void
            {
                ($this->log)('warning', $message);
            }

            public function error(string $message, array $context = []): void
            {
                ($this->log)('error', $message);
            }
        };
        return new GuidelyFlags($options + [
            'sdkKey' => self::PK,
            'cache' => new InMemoryCache(fn () => $this->time),
            'clock' => fn () => $this->time,
            'env' => fn (string $name): ?string => $this->env[$name] ?? null,
            'logger' => $logger,
            'onError' => function (\Throwable $e): void {
                $this->errors[] = $e->getMessage();
            },
            'reportUsage' => false,
            'reportExposures' => false,
        ]);
    }

    private function file(string $text): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'guidely-overrides-');
        file_put_contents($path, $text);
        return $this->files[] = $path;
    }

    /** @return list<string> */
    private function warnings(): array
    {
        return array_values(array_map(static fn (array $l) => $l[1], array_filter($this->logs, static fn (array $l) => $l[0] === 'warning')));
    }

    public function testEnvironmentVariable(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES'] = 'banner:"from env",limit:5,extra:{"a":1}';
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload)]);

        $details = $flags->getDetails('banner');
        self::assertSame('from env', $details->value);
        self::assertSame(Reason::OVERRIDE, $details->reason);
        self::assertSame(5, $flags->getNumber('limit'));
        self::assertSame(['a' => 1], $flags->getJson('extra'));
        self::assertSame(['[guidely-php] Dev overrides from GUIDELY_FLAG_OVERRIDES: 3 flags, behaviour localOverRemote.'], $this->warnings());
        self::assertSame([], $this->errors);
    }

    public function testFile(): void
    {
        $path = $this->file("\u{FEFF}{\"banner\": \"from file\", \"limit\": 7}\n");
        $this->env['GUIDELY_FLAG_OVERRIDES_FILE'] = " $path ";
        $flags = $this->client(['httpClient' => FakeHttpClient::down()]);

        self::assertSame('from file', $flags->getString('banner'));
        self::assertSame(7, $flags->getNumber('limit'));
        self::assertSame(["[guidely-php] Dev overrides from GUIDELY_FLAG_OVERRIDES_FILE ($path): 2 flags, behaviour localOverRemote."], $this->warnings());
    }

    public function testBehaviourVariableMakesTheClientHermetic(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES'] = 'banner:local';
        $this->env['GUIDELY_FLAG_OVERRIDE_BEHAVIOUR'] = ' localOnly ';
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $flags = $this->client(['httpClient' => $http, 'overrideBehaviour' => 'remoteOverLocal', 'reportUsage' => true, 'reportExposures' => true]);

        self::assertSame('local', $flags->getString('banner'));
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('limit')->reason);
        self::assertFalse($flags->refresh());
        $flags->flush();
        self::assertSame([], $http->requests);
        self::assertSame(['[guidely-php] Dev overrides from GUIDELY_FLAG_OVERRIDES, GUIDELY_FLAG_OVERRIDE_BEHAVIOUR: 1 flag, behaviour localOnly.'], $this->warnings());

        // An explicit behaviour passed to setOverrides() still applies.
        $flags->setOverrides([], OverrideBehaviour::RemoteOverLocal);
        self::assertSame('remote', $flags->getString('banner'));
    }

    public function testBehaviourAloneIsAnnounced(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDE_BEHAVIOUR'] = 'remoteOverLocal';
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload), 'overrides' => ['banner' => 'local', 'only-local' => 1]]);

        self::assertSame('remote', $flags->getString('banner'));
        self::assertSame(1, $flags->getNumber('only-local'));
        self::assertSame(['[guidely-php] Dev overrides from GUIDELY_FLAG_OVERRIDE_BEHAVIOUR: 0 flags, behaviour remoteOverLocal.'], $this->warnings());
    }

    public function testPrecedenceOptionThenFileThenEnvironment(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES_FILE'] = $this->file("banner:file\nlimit:2\n");
        $this->env['GUIDELY_FLAG_OVERRIDES'] = '{"banner": "env"}';
        $flags = $this->client([
            'httpClient' => FakeHttpClient::down(),
            'overrides' => ['banner' => 'option', 'limit' => 1, 'code-only' => true],
        ]);

        self::assertSame('env', $flags->getString('banner'));
        self::assertSame(2, $flags->getNumber('limit'));
        self::assertTrue($flags->isEnabled('code-only'));
        $all = $flags->getAll();
        self::assertSame(['banner' => 'env', 'limit' => 2, 'code-only' => true], array_map(static fn ($r) => $r->value, $all));
        self::assertCount(1, $this->warnings());
        self::assertStringContainsString('GUIDELY_FLAG_OVERRIDES_FILE (', $this->warnings()[0]);
        self::assertStringContainsString('), GUIDELY_FLAG_OVERRIDES: 2 flags', $this->warnings()[0]);
    }

    public function testSetOverridesReplacesOnlyTheCodeLayer(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES'] = 'banner:env';
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload), 'overrides' => ['limit' => 1]]);
        self::assertSame(1, $flags->getNumber('limit'));

        $flags->setOverrides(['banner' => 'code', 'other' => 3]);
        self::assertSame('env', $flags->getString('banner'));
        self::assertSame(3, $flags->getNumber('other'));
        self::assertSame(10, $flags->getNumber('limit'));

        $flags->setOverrides([]);
        self::assertSame('env', $flags->getString('banner'));
    }

    public function testDevOverridesCanBeTurnedOff(): void
    {
        $read = [];
        $payload = $this->payload();
        $flags = $this->client([
            'httpClient' => FakeHttpClient::serving($payload),
            'devOverrides' => false,
            'env' => function (string $name) use (&$read): ?string {
                $read[] = $name;
                return $name === 'GUIDELY_FLAG_OVERRIDE_BEHAVIOUR' ? 'localOnly' : 'banner:env';
            },
        ]);

        self::assertSame('remote', $flags->getString('banner'));
        self::assertSame([], $read, 'no source is read');
        self::assertSame([], $this->logs);
    }

    public function testBrokenSourcesAreReportedAndIgnored(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES'] = '{bad';
        $this->env['GUIDELY_FLAG_OVERRIDES_FILE'] = sys_get_temp_dir() . '/guidely-missing-' . bin2hex(random_bytes(4)) . '.json';
        $this->env['GUIDELY_FLAG_OVERRIDE_BEHAVIOUR'] = 'sometimes';
        $payload = $this->payload();
        $flags = $this->client(['httpClient' => FakeHttpClient::serving($payload)]);

        self::assertSame('remote', $flags->getString('banner'));
        self::assertCount(3, $this->errors);
        self::assertStringStartsWith('Could not read GUIDELY_FLAG_OVERRIDES_FILE (', $this->errors[0]);
        self::assertSame('GUIDELY_FLAG_OVERRIDES is not a JSON object or a key:value list; ignoring it.', $this->errors[1]);
        self::assertSame('GUIDELY_FLAG_OVERRIDE_BEHAVIOUR must be localOnly, localOverRemote or remoteOverLocal (got "sometimes"); ignoring it.', $this->errors[2]);
        self::assertSame([], $this->warnings(), 'no source was used');
    }

    public function testADirectoryIsNotAnOverridesFile(): void
    {
        $this->env['GUIDELY_FLAG_OVERRIDES_FILE'] = sys_get_temp_dir();
        $this->env['GUIDELY_FLAG_OVERRIDES'] = 'banner:env';
        $flags = $this->client(['httpClient' => FakeHttpClient::down()]);

        self::assertSame('env', $flags->getString('banner'));
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('it is a directory', $this->errors[0]);
        self::assertSame(['[guidely-php] Dev overrides from GUIDELY_FLAG_OVERRIDES: 1 flag, behaviour localOverRemote.'], $this->warnings());
    }

    public function testLocalOnlyIgnoresAPayloadCachedByAnEarlierRun(): void
    {
        $payload = $this->payload();
        $cache = new InMemoryCache(fn () => $this->time);
        self::assertSame('remote', $this->client(['httpClient' => FakeHttpClient::serving($payload), 'cache' => $cache])->getString('banner'));

        $http = FakeHttpClient::down();
        $flags = $this->client(['httpClient' => $http, 'cache' => $cache, 'overrides' => ['limit' => 3], 'overrideBehaviour' => 'localOnly', 'reportUsage' => true, 'reportExposures' => true]);
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('banner')->reason);
        self::assertSame(3, $flags->getNumber('limit'));
        self::assertNull($flags->getPayload());
        self::assertSame(['limit'], array_keys($flags->getAll()));
        self::assertFalse($flags->refresh());
        self::assertSame(Reason::FLAG_NOT_FOUND, $flags->getDetails('banner')->reason);
        unset($flags); // the destructor flushes: still no request
        self::assertSame([], $http->requests);

        // The same through the behaviour variable, with a bootstrap payload: served, cache untouched.
        $this->env['GUIDELY_FLAG_OVERRIDE_BEHAVIOUR'] = 'localOnly';
        $bootstrap = $this->payload();
        $bootstrap['flags']['banner']['defaultValue'] = 'bootstrap';
        $flags = $this->client(['httpClient' => $http, 'cache' => $cache, 'payload' => $bootstrap]);
        self::assertSame('bootstrap', $flags->getString('banner'));
        self::assertSame([], $http->requests);
        self::assertSame([], $this->errors);
    }

    public function testOverridesAreNeverCountedInUsage(): void
    {
        $payload = $this->payload();
        $http = FakeHttpClient::serving($payload);
        $this->env['GUIDELY_FLAG_OVERRIDES'] = 'from-env:1';
        $flags = $this->client(['httpClient' => $http, 'overrides' => ['banner' => 'local'], 'reportUsage' => true]);
        $flags->getString('banner');
        $flags->getNumber('from-env');
        $flags->getNumber('limit');
        $flags->flush();

        $events = $http->to('/api/v1/sdk/flags/events');
        self::assertCount(1, $events);
        $batch = json_decode((string) $events[0]['body'], true);
        self::assertSame([['flag' => 'limit', 'value' => '10', 'count' => 1]], $batch['usage']);
    }

    public function testDefaultLookupFallsBackToEnvAndServerArrays(): void
    {
        $name = 'GUIDELY_TEST_' . bin2hex(random_bytes(4));
        self::assertNull(DevOverrides::env($name));
        try {
            $_SERVER[$name] = 'server';
            self::assertSame('server', DevOverrides::env($name));
            $_ENV[$name] = 'env';
            self::assertSame('env', DevOverrides::env($name));
            putenv("$name=getenv");
            self::assertSame('getenv', DevOverrides::env($name));
        } finally {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
        self::assertNull(DevOverrides::env($name));
    }

    public function testReadWithTheRealEnvironment(): void
    {
        $name = DevOverrides::OVERRIDES_ENV;
        $previous = getenv($name);
        try {
            putenv("$name=banner:real");
            $dev = DevOverrides::read();
            self::assertSame('real', $dev->values['banner'] ?? null);
            self::assertContains(DevOverrides::OVERRIDES_ENV, $dev->sources);
        } finally {
            putenv($previous === false ? $name : "$name=$previous");
        }
    }
}
