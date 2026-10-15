<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\RecContext;

interface CandidateGenerator
{
    public function name(): string;

    /**
     * @return list<Candidate>
     */
    public function generate(RecContext $ctx, int $limit, string $index = 'default'): array;
}
