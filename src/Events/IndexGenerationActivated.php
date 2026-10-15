<?php

declare(strict_types=1);

namespace Relevance\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class IndexGenerationActivated
{
    use Dispatchable;

    public function __construct(
        public readonly string $index,
        public readonly int $generation,
    ) {}
}
