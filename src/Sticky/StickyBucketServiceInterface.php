<?php

declare(strict_types=1);

namespace GuidelyTech\Flags\Sticky;

/**
 * Storage for sticky bucket documents (docs/feature-flags-spec.md §3.5).
 *
 * Documents are keyed `"<attributeName>||<attributeValue>"` and map
 * `"<experimentKey>__<bucketVersion>"` to a variation id. Implement this on top of your own
 * database, Redis or session to keep users in their variation across requests and config changes.
 */
interface StickyBucketServiceInterface
{
    /**
     * @return array<string, string>|null the document's assignments, or null when there is none
     */
    public function getAssignments(string $docKey): ?array;

    /**
     * Replace the document's assignments.
     *
     * @param array<string, string> $assignments
     */
    public function saveAssignments(string $docKey, array $assignments): void;
}
