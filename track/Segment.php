<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * One transaction being recorded: the child spans that have ended (at most `maxSpans`), how it got
 * its name, and the request it serves.
 *
 * @internal
 */
final class Segment
{
    /** @var list<array<string, mixed>> */
    public array $spans = [];
    public int $dropped = 0;
    public bool $finished = false;
    /** `custom`, `route` (a framework's matched route) or `url`. */
    public string $source = 'custom';
    /** @var array{method?: string, url?: string}|null the request a request transaction serves */
    public ?array $request = null;

    public function __construct(
        public readonly int $maxSpans,
        public readonly ?float $sampleRate,
    ) {
    }

    /** @param array<string, mixed> $span */
    public function add(array $span): void
    {
        if ($this->finished) {
            return;
        }
        if (count($this->spans) >= $this->maxSpans) {
            $this->dropped++;
        } else {
            $this->spans[] = $span;
        }
    }
}
