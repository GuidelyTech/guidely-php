<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Tests\Fixtures;

// Line numbers matter: ErrorCaptureTest asserts them.

final class TraceFixture
{
    public function outer(): void
    {
        $this->inner(); // line 13
    }

    private function inner(): void
    {
        $run = static function (): void {
            throw new \RuntimeException('from the closure'); // line 19
        };
        $run(); // line 21
    }

    public static function wrapped(): void
    {
        try {
            (new self())->outer(); // line 27
        } catch (\RuntimeException $e) {
            throw new \LogicException('wrapped', 7, $e); // line 29
        }
    }

    public static function mapped(): array
    {
        return array_map(static function (int $x): int {
            throw new \DomainException("bad $x"); // line 36
        }, [1]); // array_map() is called on line 35
    }
}
