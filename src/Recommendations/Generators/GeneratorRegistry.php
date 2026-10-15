<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Generators;

class GeneratorRegistry
{
    /** @var array<string, CandidateGenerator> */
    private array $generators = [];

    public function __construct()
    {
        $this->register(new ItemNeighborsGenerator());
        $this->register(new PopularGenerator());
        $this->register(new TrendingGenerator());
        $this->register(new RecentlyViewedGenerator());
        $this->register(new ContentSimilarGenerator());
    }

    public function register(CandidateGenerator $generator): self
    {
        $this->generators[$generator->name()] = $generator;
        return $this;
    }

    public function get(string $name): ?CandidateGenerator
    {
        // Handle generator:variant syntax, e.g. item_neighbors:purchase -> item_neighbors
        $baseName = explode(':', $name)[0];
        return $this->generators[$baseName] ?? null;
    }

    public function has(string $name): bool
    {
        $baseName = explode(':', $name)[0];
        return isset($this->generators[$baseName]);
    }
}
