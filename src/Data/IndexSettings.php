<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class IndexSettings
{
    /**
     * @param array<string, list<string>> $synonyms word => list of synonyms
     * @param list<string> $stopWords
     * @param list<string> $rankingRules
     * @param array<string, mixed> $options
     */
    public function __construct(
        public array $synonyms = [],
        public array $stopWords = [],
        public array $rankingRules = [],
        public array $options = [],
    ) {}
}
