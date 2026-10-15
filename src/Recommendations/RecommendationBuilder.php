<?php

declare(strict_types=1);

namespace Relevance\Recommendations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Relevance\Data\Hit;
use Relevance\Recommendations\Data\Candidate;
use Relevance\Recommendations\Data\PlacementDefinition;
use Relevance\Recommendations\Data\RecContext;
use Relevance\Recommendations\Data\RecResult;
use Relevance\Search\Hydration\EloquentHydrator;

class RecommendationBuilder
{
    private ?string $userId = null;
    private ?string $anonId = null;
    private ?string $seedItem = null;
    private ?string $sessionId = null;
    private ?string $tenantId = null;
    private ?string $segment = null;
    /** @var array<string, mixed> */
    private array $context = [];
    private int $limit = 20;

    public function __construct(
        private readonly PlacementRunner $runner,
        private readonly string|PlacementDefinition $placement,
    ) {}

    public function for(mixed $user): self
    {
        if ($user instanceof Model) {
            $this->userId = (string) $user->getKey();
        } elseif (is_object($user) && isset($user->id)) {
            $this->userId = (string) $user->id;
        } elseif (is_string($user) || is_int($user)) {
            $this->userId = (string) $user;
        }

        return $this;
    }

    public function anon(string $anonId): self
    {
        $this->anonId = $anonId;
        return $this;
    }

    public function seed(mixed $item): self
    {
        if ($item instanceof Model) {
            $this->seedItem = (string) $item->getKey();
        } elseif (is_object($item) && isset($item->id)) {
            $this->seedItem = (string) $item->id;
        } elseif (is_string($item) || is_int($item)) {
            $this->seedItem = (string) $item;
        }

        return $this;
    }

    public function session(string $sessionId): self
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function tenant(?string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function segment(string $segment): self
    {
        $this->segment = $segment;
        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function context(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function get(): RecResult
    {
        $placementName = is_string($this->placement) ? $this->placement : $this->placement->name;

        $ctx = new RecContext(
            userId: $this->userId,
            anonId: $this->anonId,
            seedItem: $this->seedItem,
            sessionId: $this->sessionId,
            tenantId: $this->tenantId,
            segment: $this->segment,
            placement: $placementName,
            context: $this->context,
            limit: $this->limit,
        );

        return $this->runner->run($this->placement, $ctx);
    }

    /**
     * @template TModel of Model
     * @param class-string<TModel> $modelClass
     * @return EloquentCollection<int, TModel>
     */
    public function models(string $modelClass): EloquentCollection
    {
        $result = $this->get();
        if (empty($result->candidates)) {
            return new EloquentCollection();
        }

        $hits = array_map(static fn (Candidate $c) => new Hit(
            id: $c->id,
            score: $c->score,
        ), $result->candidates);

        $hydrator = new EloquentHydrator();
        /** @var EloquentCollection<int, TModel> $models */
        $models = $hydrator->hydrate($modelClass, $hits);
        return $models;
    }
}
