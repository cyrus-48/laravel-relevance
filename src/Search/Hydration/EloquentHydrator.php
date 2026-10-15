<?php

declare(strict_types=1);

namespace Relevance\Search\Hydration;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Relevance\Data\Hit;
use Relevance\Events\StaleHitDetected;

final readonly class EloquentHydrator
{
    /**
     * @param class-string<Model> $modelClass
     * @param list<Hit> $hits
     * @param list<string> $with
     * @return EloquentCollection<int, Model>
     */
    public function hydrate(string $modelClass, array $hits, array $with = [], string $index = 'default'): EloquentCollection
    {
        if (empty($hits)) {
            return new EloquentCollection();
        }

        $ids = array_map(static fn (Hit $hit): string => $hit->id, $hits);

        /** @var EloquentCollection<int, Model> $models */
        $models = $modelClass::query()
            ->with($with)
            ->whereIn((new $modelClass())->getKeyName(), $ids)
            ->get();

        $dictionary = $models->getDictionary();
        $ordered = new EloquentCollection();
        $missingIds = [];

        foreach ($ids as $id) {
            if (isset($dictionary[$id])) {
                $ordered->push($dictionary[$id]);
            } else {
                $missingIds[] = $id;
                event(new StaleHitDetected($index, $id));
            }
        }

        return $ordered;
    }
}
