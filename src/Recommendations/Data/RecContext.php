<?php

declare(strict_types=1);

namespace Relevance\Recommendations\Data;

final readonly class RecContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?string $userId = null,
        public ?string $anonId = null,
        public ?string $seedItem = null,
        public ?string $sessionId = null,
        public ?string $tenantId = null,
        public ?string $segment = null,
        public ?string $placement = null,
        public array $context = [],
        public int $limit = 20,
    ) {}
}
