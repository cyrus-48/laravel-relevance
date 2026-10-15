<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\IndexSchema;
use Relevance\Data\IndexSettings;
use Relevance\Data\IndexStats;

interface IndexAdmin extends Driver
{
    public function create(IndexSchema $schema, string $physicalName): void;

    public function drop(string $physicalName): void;

    public function swapAlias(string $alias, string $physicalName): void;

    public function resolveAlias(string $alias): ?string;

    public function stats(string $physicalName): IndexStats;

    public function applySettings(string $physicalName, IndexSettings $settings): void;
}
