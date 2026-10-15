<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

use Relevance\Data\Hit;

final readonly class RulePostEvaluation
{
    /**
     * @param list<Hit> $hits
     * @param list<string> $rulesApplied
     */
    public function __construct(
        public array $hits,
        public array $rulesApplied = [],
    ) {}
}
