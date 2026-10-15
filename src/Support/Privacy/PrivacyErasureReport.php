<?php

declare(strict_types=1);

namespace Relevance\Support\Privacy;

final readonly class PrivacyErasureReport
{
    /**
     * @param array<string, int> $deletedCounts
     */
    public function __construct(
        public string $userRef,
        public array $deletedCounts,
        public int $totalDeleted,
    ) {}
}
