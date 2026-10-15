<?php

declare(strict_types=1);

namespace Relevance\Data;

final readonly class Document
{
    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $vectorInputs
     * @param list<string> $acl
     */
    public function __construct(
        public string $id,
        public int $version = 1,
        public array $fields = [],
        public array $vectorInputs = [],
        public ?string $tenant = null,
        public array $acl = ['public'],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $default;
    }
}
