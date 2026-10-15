<?php

declare(strict_types=1);

namespace Relevance\Contracts;

interface Driver
{
    public function name(): string;

    public function capabilities(): CapabilitySet;
}
