<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\IndexSchema;
use Relevance\Filters\Filter;

interface FilterCompiler
{
    public function compile(Filter $filter, ?IndexSchema $schema = null): mixed;
}
