<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Data;

final readonly class PlacementDefinition
{
    /**
     * @param array<string, float> $generators
     * @param list<string> $filters
     * @param array<string, mixed> $diversify
     * @param array<string, mixed> $explore
     * @param list<string> $fallback
     */
    public function __construct(
        public string $name,
        public string $index = 'default',
        public array $generators = [],
        public array $filters = [],
        public string $ranker = 'rrf',
        public array $diversify = [],
        public array $explore = [],
        public int $limit = 20,
        public array $fallback = ['popular'],
        public int $cacheTtl = 900,
        public int $pool = 100,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        /** @var array<string, float> $generators */
        $generators = array_map('floatval', (array) ($config['generators'] ?? []));

        /** @var list<string> $filters */
        $filters = array_values(array_map('strval', (array) ($config['filters'] ?? [])));

        /** @var list<string> $fallback */
        $fallback = array_values(array_map('strval', (array) ($config['fallback'] ?? ['popular'])));

        /** @var array<string, mixed> $diversify */
        $diversify = (array) ($config['diversify'] ?? []);

        /** @var array<string, mixed> $explore */
        $explore = (array) ($config['explore'] ?? []);

        return new self(
            name: $name,
            index: (string) ($config['index'] ?? 'default'),
            generators: $generators,
            filters: $filters,
            ranker: (string) ($config['ranker'] ?? 'rrf'),
            diversify: $diversify,
            explore: $explore,
            limit: (int) ($config['limit'] ?? 20),
            fallback: $fallback,
            cacheTtl: (int) ($config['cache_ttl'] ?? 900),
            pool: (int) ($config['pool'] ?? 100),
        );
    }
}
