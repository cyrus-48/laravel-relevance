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

    /** @var array<class-string<Model>, true> models that already have the outbox observer attached */
    private array $observed = [];

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

    /**
     * Attach the outbox observer to a model exactly once per registry instance.
     *
     * Called from the `Searchable` trait's boot hook, so models using the trait are
     * indexed without being listed anywhere in config. Idempotent: calling it again
     * (or via registerObservers()) never produces duplicate outbox rows.
     *
     * @param class-string<Model> $modelClass
     */
    public function observeModel(string $modelClass): void
    {
        if (isset($this->observed[$modelClass])) {
            return;
        }

        $this->observed[$modelClass] = true;
        $modelClass::observe(new SearchableObserver($this));
    }

    public function registerObservers(): void
    {
        foreach (array_keys($this->modelMap) as $modelClass) {
            $this->observeModel($modelClass);
        }
    }
}
