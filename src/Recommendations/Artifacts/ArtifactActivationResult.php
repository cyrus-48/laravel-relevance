<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Artifacts;

final readonly class ArtifactActivationResult
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public bool $passed,
        public string $modelVersion,
        public string $algo,
        public array $metrics = [],
        public ?string $failureReason = null,
    ) {}
}
