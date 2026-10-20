<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\EvaluationResult;
use GuidelyTech\Flags\Evaluator;
use GuidelyTech\Flags\GuidelyFlags;
use GuidelyTech\Flags\Hash;
use GuidelyTech\Flags\Json;
use GuidelyTech\Flags\Overrides;
use GuidelyTech\Flags\Sticky\InMemoryStickyBucketService;
use GuidelyTech\Flags\Values;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared conformance vectors (sdks/conformance/flag-vectors.json, spec §5). */
final class ConformanceTest extends TestCase
{
    private static ?array $vectors = null;

    private static function vectors(): array
    {
        if (self::$vectors === null) {
            $raw = file_get_contents(__DIR__ . '/../../conformance/flag-vectors.json');
            self::assertNotFalse($raw, 'flag-vectors.json not found');
            self::$vectors = Json::asMap(Json::decode($raw));
        }
        return self::$vectors;
    }

    /** @return iterable<string, array{array}> */
    private static function section(string $name, callable $label, ?array $from = null): iterable
    {
        foreach (Json::asList(($from ?? self::vectors())[$name] ?? null) as $i => $v) {
            $v = Json::asMap($v);
            yield sprintf('#%d %s', $i, $label($v)) => [$v];
        }
    }

    public static function hashVectors(): iterable
    {
        return self::section('hash', fn (array $v) => json_encode([$v['seed'], $v['value']], JSON_UNESCAPED_UNICODE));
    }

    public static function sha256Vectors(): iterable
    {
        return self::section('sha256', fn (array $v) => substr($v['input'], 0, 20));
    }

    public static function semverVectors(): iterable
    {
        return self::section('semver', fn (array $v) => "{$v['a']} vs {$v['b']}");
    }

    public static function cases(): iterable
    {
        return self::section('cases', fn (array $v) => $v['name']);
    }

    public static function overridesTextVectors(): iterable
    {
        return self::section('text', fn (array $v) => json_encode($v['input'], JSON_UNESCAPED_UNICODE), Json::asMap(self::vectors()['overrides'] ?? null));
    }

    public static function overridesCases(): iterable
    {
        return self::section('cases', fn (array $v) => $v['name'], Json::asMap(self::vectors()['overrides'] ?? null));
    }

    #[DataProvider('hashVectors')]
    public function testHash(array $v): void
    {
        self::assertSame((float) $v['expected'], Hash::bucket($v['seed'], $v['value']));
    }

    #[DataProvider('sha256Vectors')]
    public function testSha256(array $v): void
    {
        self::assertSame($v['expected'], Hash::sha256Hex($v['input']));
    }

    #[DataProvider('semverVectors')]
    public function testSemver(array $v): void
    {
        $a = Values::parseSemver($v['a']);
        $b = Values::parseSemver($v['b']);
        if ($v['expected'] === null) {
            self::assertTrue($a === null || $b === null, 'expected an invalid version');
            return;
        }
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($v['expected'], Values::compareSemver($a, $b));
    }

    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        $sticky = isset($case['stickyBuckets'])
            ? new InMemoryStickyBucketService(Json::asMap($case['stickyBuckets']))
            : null;
        $now = $case['now'] ?? null;
        foreach (Json::asList($case['evaluations']) as $i => $evaluation) {
            $evaluation = Json::asMap($evaluation);
            $attributes = $evaluation['attributes'] ?? [];
            $result = Evaluator::evaluate($case['payload'], $evaluation['flag'], is_array($attributes) || is_object($attributes) ? $attributes : [], $now, $sticky);
            $this->assertMatches(Json::asMap($evaluation['expected']), $result, sprintf('%s — evaluation #%d (%s, %s)', $case['name'], $i, $evaluation['flag'], Json::encode($attributes)));
        }
    }

    #[DataProvider('overridesTextVectors')]
    public function testOverridesText(array $v): void
    {
        $actual = Overrides::parse($v['input']);
        if ($v['expected'] === null) {
            self::assertNull($actual, 'expected the text to be rejected');
            return;
        }
        self::assertNotNull($actual, 'expected the text to parse');
        $expected = Json::asMap($v['expected']); // `{}` decodes as stdClass
        self::assertSame(array_map('strval', array_keys($expected)), array_map('strval', array_keys($actual)), 'keys (in order)');
        foreach ($expected as $key => $value) {
            self::assertTrue(Values::jsonEquals($value, $actual[$key]), "value of $key: expected " . Json::encode($value) . ', got ' . Json::encode($actual[$key]));
        }
    }

    #[DataProvider('overridesCases')]
    public function testOverridesCase(array $case): void
    {
        $http = new FakeHttpClient(static fn () => new \LogicException('no request expected'));
        $errors = [];
        $flags = new GuidelyFlags([
            'payload' => $case['payload'] === null ? null : Json::asMap($case['payload']),
            'overrides' => Json::asMap($case['overrides']),
            'overrideBehaviour' => $case['behaviour'],
            'cache' => false,
            'httpClient' => $http,
            'devOverrides' => false,
            'reportUsage' => false,
            'reportExposures' => false,
            'onError' => function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        ]);
        foreach (Json::asList($case['evaluations']) as $i => $evaluation) {
            $evaluation = Json::asMap($evaluation);
            $attributes = $evaluation['attributes'] ?? [];
            $result = $flags->getDetails($evaluation['flag'], null, is_array($attributes) || is_object($attributes) ? $attributes : []);
            $this->assertMatches(Json::asMap($evaluation['expected']), $result, sprintf('%s — evaluation #%d (%s)', $case['name'], $i, $evaluation['flag']));
        }
        self::assertSame([], $http->requests);
        self::assertSame([], $errors);
    }

    private function assertMatches(array $expected, EvaluationResult $result, string $context): void
    {
        $actual = $result->toArray();
        $context .= "\nactual: " . Json::encode($actual);
        if (array_key_exists('value', $expected)) {
            self::assertTrue(Values::jsonEquals($expected['value'], $result->value), "value mismatch: expected " . Json::encode($expected['value']) . "\n$context");
        }
        foreach (['reason', 'ruleId', 'variationId'] as $field) {
            if (array_key_exists($field, $expected)) {
                self::assertSame($expected[$field], $result->{$field}, "$field mismatch\n$context");
            }
        }
        if (array_key_exists('inExperiment', $expected)) {
            self::assertSame($expected['inExperiment'], $result->inExperiment, "inExperiment mismatch\n$context");
        }
    }

    public function testVectorCounts(): void
    {
        $cases = Json::asList(self::vectors()['cases']);
        $evaluations = array_sum(array_map(fn ($c) => count(Json::asMap($c)['evaluations']), $cases));
        self::assertGreaterThanOrEqual(86, count($cases));
        self::assertGreaterThanOrEqual(501, $evaluations);
        $overrides = Json::asMap(self::vectors()['overrides'] ?? null);
        self::assertGreaterThanOrEqual(19, count(Json::asList($overrides['text'] ?? null)));
        self::assertGreaterThanOrEqual(5, count(Json::asList($overrides['cases'] ?? null)));
    }

    public function testTextFormOfNumbers(): void
    {
        self::assertSame('3', Values::textOf(3.0));
        self::assertSame('-12', Values::textOf(-12));
        self::assertSame('0', Values::textOf(-0.0));
        self::assertSame('1.5', Values::textOf(1.5));
        self::assertSame('0.1', Values::textOf(0.1));
        self::assertSame('100000000000000000000', Values::textOf(1e20));
        self::assertSame('1e+21', Values::textOf(1e21));
        self::assertSame('1.5e-7', Values::textOf(1.5e-7));
        self::assertSame('0.000001', Values::textOf(1e-6));
        self::assertSame('123.456', Values::textOf(123.456));
        self::assertSame('0.30000000000000004', Values::textOf(0.1 + 0.2));
        self::assertNull(Values::textOf(INF));
        self::assertNull(Values::textOf([1]));
    }

    public function testInstantsAreStrict(): void
    {
        self::assertNull(Values::instantOf('2026-02-31'));
        self::assertNull(Values::instantOf('2025-02-29'));
        self::assertNull(Values::instantOf('2026-01-01T24:00'));
        self::assertNull(Values::instantOf('2026-01-01T00:60'));
        self::assertNull(Values::instantOf('2026-01-01T00:00:60Z'));
        self::assertSame(1709164800000.0, Values::instantOf('2024-02-29'));
        self::assertSame(-60589296000000.0, Values::instantOf('0050-01-01'));
        self::assertSame(1767225600123.0, Values::instantOf('2026-01-01t00:00:00.123999z'));
        self::assertSame(1767225600000.0, Values::instantOf('2026-01-01 01:00:00+01:00'));
    }

    public function testJsonModelKeepsEmptyObjectsDistinct(): void
    {
        $v = Json::decode('{"a":{},"b":[],"c":{"0":"x"},"d":{"k":1}}');
        self::assertSame('object', Values::jsonTypeOf($v['a']));
        self::assertSame('array', Values::jsonTypeOf($v['b']));
        self::assertSame('object', Values::jsonTypeOf($v['c']));
        self::assertSame(['k' => 1], $v['d']);
        self::assertFalse(Values::jsonEquals($v['a'], $v['b']));
        self::assertTrue(Values::jsonEquals(['x' => 1, 'y' => [1, 2.0]], (object) ['y' => [1.0, 2], 'x' => 1.0]));
    }
}
