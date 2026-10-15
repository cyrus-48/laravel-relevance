<?php

declare(strict_types=1);

namespace Relevance\Schema;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relevance\Indexing\SearchableObserver;

class IndexRegistry
{
    /** @var array<string, IndexDefinition> name => definition */
    private array $definitions = [];

    /** @var array<class-string<Model>, IndexDefinition> modelClass => definition */
    private array $modelMap = [];

    /**
     * @param list<class-string<IndexDefinition>|IndexDefinition> $indexes
     */
    public function __construct(array $indexes = [])
    {
        foreach ($indexes as $index) {
            $this->register($index);
        }
    }

    public function register(string|IndexDefinition $index): self
    {
        $def = is_string($index) ? new $index() : $index;
        if (!$def instanceof IndexDefinition) {
            throw new InvalidArgumentException("Index must extend IndexDefinition.");
        }

        $this->definitions[$def->name()] = $def;

        return $this;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public function registerModel(string $modelClass, string|IndexDefinition $index): self
    {
        $def = is_string($index) ? ($this->definitions[$index] ?? new $index()) : $index;
        if (!$def instanceof IndexDefinition) {
            throw new InvalidArgumentException("Index must extend IndexDefinition.");
        }

        $this->modelMap[$modelClass] = $def;
        $this->definitions[$def->name()] = $def;

        return $this;
    }

    public function get(string $name): ?IndexDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * @param class-string<Model>|Model $model
     */
    public function forModel(string|Model $model): ?IndexDefinition
    {
        /** @var class-string<Model> $class */
        $class = is_object($model) ? $model::class : $model;

        if (isset($this->modelMap[$class])) {
            return $this->modelMap[$class];
        }

        if (method_exists($class, 'relevanceIndex')) {
            $indexClass = $class::relevanceIndex();
            if ($indexClass !== null) {
                $def = new $indexClass();
                if ($def instanceof IndexDefinition) {
                    $this->modelMap[$class] = $def;
                    $this->definitions[$def->name()] = $def;
                    return $def;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, IndexDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function registerObservers(): void
    {
        $observer = new SearchableObserver($this);

        foreach ($this->modelMap as $modelClass => $def) {
            $modelClass::observe($observer);
        }
    }
}
