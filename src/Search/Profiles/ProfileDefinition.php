<?php

declare(strict_types=1);

namespace Relevance\Search\Profiles;

final readonly class ProfileDefinition
{
    /**
     * @param array<string, float> $fields
     * @param array<string, mixed> $fusion
     * @param array<int, array<string, mixed>> $boosts
     * @param array<string, mixed> $rerank
     * @param array<string, mixed> $personalize
     * @param array<string, mixed> $diversify
     */
    public function __construct(
        public string $name,
        public string $index,
        public string $mode = 'hybrid',
        public float $semanticRatio = 0.5,
        public int $candidatePool = 300,
        public array $fields = [],
        public array $fusion = ['type' => 'rrf', 'k' => 60],
        public array $boosts = [],
        public float $boostCap = 0.35,
        public array $rerank = [],
        public array $personalize = [],
        public array $diversify = [],
        public int $budgetMs = 250,
        public int $version = 1,
        public ?string $extends = null,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        /** @var array<string, float> $fields */
        $fields = array_map('floatval', (array) ($config['fields'] ?? []));

        /** @var array<string, mixed> $fusion */
        $fusion = (array) ($config['fusion'] ?? ['type' => 'rrf', 'k' => 60]);

        /** @var array<int, array<string, mixed>> $boosts */
        $boosts = array_values((array) ($config['boosts'] ?? []));

        /** @var array<string, mixed> $rerank */
        $rerank = (array) ($config['rerank'] ?? []);

        /** @var array<string, mixed> $personalize */
        $personalize = (array) ($config['personalize'] ?? []);

        /** @var array<string, mixed> $diversify */
        $diversify = (array) ($config['diversify'] ?? []);

        return new self(
            name: $name,
            index: (string) ($config['index'] ?? 'default'),
            mode: (string) ($config['mode'] ?? 'hybrid'),
            semanticRatio: (float) ($config['semantic_ratio'] ?? 0.5),
            candidatePool: (int) ($config['candidate_pool'] ?? 300),
            fields: $fields,
            fusion: $fusion,
            boosts: $boosts,
            boostCap: (float) ($config['boost_cap'] ?? 0.35),
            rerank: $rerank,
            personalize: $personalize,
            diversify: $diversify,
            budgetMs: (int) ($config['budget_ms'] ?? 250),
            version: (int) ($config['version'] ?? 1),
            extends: isset($config['extends']) ? (string) $config['extends'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'mode' => $this->mode,
            'semantic_ratio' => $this->semanticRatio,
            'candidate_pool' => $this->candidatePool,
            'fields' => $this->fields,
            'fusion' => $this->fusion,
            'boosts' => $this->boosts,
            'boost_cap' => $this->boostCap,
            'rerank' => $this->rerank,
            'personalize' => $this->personalize,
            'diversify' => $this->diversify,
            'budget_ms' => $this->budgetMs,
            'version' => $this->version,
            'extends' => $this->extends,
        ];
    }
}
