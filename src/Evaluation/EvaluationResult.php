<?php

declare(strict_types=1);

namespace Relevance\Evaluation;

final readonly class EvaluationResult
{
    /**
     * @param array<string, float|int> $metrics
     * @param array{wins: list<string>, losses: list<string>, ties: list<string>} $diff
     * @param list<string> $failureReasons
     */
    public function __construct(
        public string $index,
        public string $judgmentSet,
        public string $profile,
        public ?string $baselineProfile = null,
        public array $metrics = [],
        public array $diff = ['wins' => [], 'losses' => [], 'ties' => []],
        public bool $passed = true,
        public array $failureReasons = [],
    ) {}
}
