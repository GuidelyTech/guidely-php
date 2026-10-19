<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests;

use GuidelyTech\Flags\EvaluationResult;
use GuidelyTech\Flags\Evaluator;
use GuidelyTech\Flags\Hash;
use GuidelyTech\Flags\Json;
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
    private static function section(string $name, callable $label): iterable
    {
        foreach (Json::asList(self::vectors()[$name] ?? null) as $i => $v) {
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
