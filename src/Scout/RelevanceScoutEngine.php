<?php

declare(strict_types=1);

namespace Relevance\Scout;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Relevance\Data\Document;
use Relevance\Data\Hit;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Facades\Relevance;
use Relevance\Search\Hydration\EloquentHydrator;
use Relevance\Contracts\IndexAdmin;
use Relevance\Contracts\Indexer;

class RelevanceScoutEngine
{
    /**
     * Update the given model in the index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function update(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model $first */
        $first = $models->first();
        $index = method_exists($first, 'searchableAs') ? (string) $first->searchableAs() : $first->getTable();
        $docs = [];

        foreach ($models as $model) {
            /** @var array<string, mixed> $array */
            $array = method_exists($model, 'toSearchableArray')
                ? $model->toSearchableArray()
                : $model->toArray();

            $id = method_exists($model, 'getScoutKey')
                ? (string) $model->getScoutKey()
                : (string) $model->getKey();

            $docs[] = new Document(
                id: $id,
                version: 1,
                fields: $array,
            );
        }

        $driver = Relevance::driver();
        if ($driver instanceof Indexer) {
            $driver->upsert($index, $docs);
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param EloquentCollection<int, Model> $models
     */
    public function delete(EloquentCollection $models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        /** @var Model $first */
        $first = $models->first();
        $index = method_exists($first, 'searchableAs') ? (string) $first->searchableAs() : $first->getTable();
        $ids = $models->map(static function (Model $m): string {
            return method_exists($m, 'getScoutKey')
                ? (string) $m->getScoutKey()
                : (string) $m->getKey();
        })->all();

        $driver = Relevance::driver();
        if ($driver instanceof Indexer) {
            $driver->delete($index, array_values($ids));
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param mixed $builder
     */
    public function search(mixed $builder): SearchResponse
    {
        /** @var Model $model */
        $model = $builder->model;
        $index = $builder->index ?? (method_exists($model, 'searchableAs') ? (string) $model->searchableAs() : $model->getTable());
        $limit = $builder->limit ?? 20;

        $request = new SearchRequest(
            index: $index,
            query: (string) $builder->query,
            limit: (int) $limit,
        );

        return Relevance::search($request, $index);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param mixed $builder
     * @param int|string $perPage
     * @param int|string $page
     * @return LengthAwarePaginator<int, mixed>
     */
    public function paginate(mixed $builder, $perPage, $page): LengthAwarePaginator
    {
        /** @var Model $model */
        $model = $builder->model;
        $index = $builder->index ?? (method_exists($model, 'searchableAs') ? (string) $model->searchableAs() : $model->getTable());

        $request = new SearchRequest(
            index: $index,
            query: (string) $builder->query,
            limit: (int) $perPage,
            options: ['offset' => ((int) $page - 1) * (int) $perPage],
        );

        $response = Relevance::search($request, $index);
        /** @var EloquentCollection<int, Model> $models */
        $models = $this->map($builder, $response, $model);

        return new LengthAwarePaginator(
            items: $models,
            total: $response->total,
            perPage: (int) $perPage,
            currentPage: (int) $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]
        );
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param SearchResponse $results
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        return collect($results->hits)->map(static fn (Hit $h) => $h->id);
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @template TModel of Model
     * @param mixed $builder
     * @param SearchResponse $results
     * @param TModel $model
     * @return EloquentCollection<int, TModel>
     */
    public function map(mixed $builder, $results, $model): EloquentCollection
    {
        if (empty($results->hits)) {
            return new EloquentCollection();
        }

        $hydrator = new EloquentHydrator();
        /** @var class-string<TModel> $modelClass */
        $modelClass = $model::class;

        /** @var EloquentCollection<int, TModel> $hydrated */
        $hydrated = $hydrator->hydrate($modelClass, $results->hits);

        return $hydrated;
    }

    /**
     * Get the total count of the given results.
     *
     * @param SearchResponse $results
     */
    public function getTotalCount($results): int
    {
        return $results->total;
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param Model $model
     */
    public function flush($model): void
    {
        $index = method_exists($model, 'searchableAs') ? (string) $model->searchableAs() : $model->getTable();
        $driver = Relevance::driver();
        if ($driver instanceof IndexAdmin) {
            $driver->drop($index);
        }
    }
}
