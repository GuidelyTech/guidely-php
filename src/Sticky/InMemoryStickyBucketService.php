<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Sticky;

/** Sticky bucket documents held in memory (tests, long-running workers, or as a base class). */
class InMemoryStickyBucketService implements StickyBucketServiceInterface
{
    /** @var array<string, array<string, string>> */
    private array $docs = [];

    /**
     * @param array<string, array<string, string>> $documents initial documents
     */
    public function __construct(array $documents = [])
    {
        foreach ($documents as $key => $doc) {
            $this->docs[(string) $key] = $doc;
        }
    }

    public function getAssignments(string $docKey): ?array
    {
        return $this->docs[$docKey] ?? null;
    }

    public function saveAssignments(string $docKey, array $assignments): void
    {
        $this->docs[$docKey] = $assignments;
    }

    /** @return array<string, array<string, string>> every document */
    public function all(): array
    {
        return $this->docs;
    }
}
