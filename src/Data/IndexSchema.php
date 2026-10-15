<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class IndexSchema
{
    /**
     * @param array<string, mixed> $fields field_name => field_definition
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $name,
        public array $fields = [],
        public ?IndexSettings $settings = null,
        public ?int $embeddingDimensions = null,
        public ?string $embeddingFingerprint = null,
        public array $options = [],
    ) {}
}
