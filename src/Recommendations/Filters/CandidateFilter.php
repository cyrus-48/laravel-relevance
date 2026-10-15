<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Filters;

use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

interface CandidateFilter
{
    public function name(): string;

    /**
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    public function apply(array $candidates, RecContext $ctx, string $index = 'default'): array;
}
