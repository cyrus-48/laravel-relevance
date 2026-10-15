<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

use Relevance\Data\SearchRequest;
use Relevance\Search\Profiles\ProfileDefinition;

final readonly class RulePreEvaluation
{
    /**
     * @param list<string> $rulesApplied
     */
    public function __construct(
        public SearchRequest $request,
        public ProfileDefinition $profile,
        public ?string $redirectUrl = null,
        public array $rulesApplied = [],
    ) {}
}
