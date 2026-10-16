<?php

declare(strict_types=1);

namespace Relevance\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Relevance\Facades\Relevance;
use Relevance\Recommendations\RecommendationBuilder;
use Relevance\Schema\IndexRegistry;
use Relevance\Search\SearchBuilder;

trait Searchable
{
    /**
     * Eloquent boot hook: attach the transactional-outbox observer to this model.
     * Without this, models using the trait were only indexed if they had been
     * registered manually before the service provider booted.
     */
    public static function bootSearchable(): void
    {
        app(IndexRegistry::class)->observeModel(static::class);
    }

    /**
     * Start a relevance search query on this model.
     */
    public static function relevance(string $query = ''): SearchBuilder
    {
        return (new SearchBuilder(static::class))->query($query);
    }

    /**
     * Get the index definition class for this model.
     * Can be overridden by the model.
     */
    public static function relevanceIndex(): ?string
    {
        return null;
    }

    /**
     * Find items similar to this model instance.
     *
     * @return EloquentCollection<int, static>
     */
    public function similar(int $limit = 10): EloquentCollection
    {
        /** @var EloquentCollection<int, static> $models */
        $models = Relevance::placement('product.similar')
            ->seed($this)
            ->limit($limit)
            ->models(static::class);

        return $models;
    }

    /**
     * Start a recommendation query for a user.
     */
    public static function recommendFor(mixed $user, int $limit = 20): RecommendationBuilder
    {
        return Relevance::placement('home.for_you')
            ->for($user)
            ->limit($limit);
    }

    /**
     * Start a recommendation query for items also interacted with.
     */
    public static function alsoInteracted(mixed $product, string $interaction = 'purchase', int $limit = 5): RecommendationBuilder
    {
        return Relevance::placement("product.also_{$interaction}")
            ->seed($product)
            ->limit($limit);
    }

    /**
     * Start a recommendation query for trending items.
     */
    public static function trending(string $window = '24h', ?string $segment = null, int $limit = 10): RecommendationBuilder
    {
        $builder = Relevance::placement('home.trending')
            ->context(['window' => $window])
            ->limit($limit);

        if ($segment !== null) {
            $builder->segment($segment);
        }

        return $builder;
    }

    /**
     * Start a recommendation query for next items in a session.
     */
    public static function nextFromSession(string $sessionId, int $limit = 8): RecommendationBuilder
    {
        return Relevance::placement('session.next')
            ->session($sessionId)
            ->limit($limit);
    }
}
