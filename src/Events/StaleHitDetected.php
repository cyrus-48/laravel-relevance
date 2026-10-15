<?php

declare(strict_types=1);

namespace Relevance\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class StaleHitDetected
{
    use Dispatchable;

    public function __construct(
        public readonly string $index,
        public readonly string $docId,
    ) {}
}
