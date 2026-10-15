<?php

declare(strict_types=1);

namespace Relevance\Data;

use Relevance\Support\Deadline;

final readonly class RerankOptions
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public ?Deadline $deadline = null,
        public int $topR = 50,
        public ?string $model = null,
        public array $extra = [],
    ) {}
}
