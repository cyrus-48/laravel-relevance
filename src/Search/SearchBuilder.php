<?php

declare(strict_types=1);

namespace Relevance\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Relevance\Data\SearchContext;
use Relevance\Data\SearchMode;
use Relevance\Data\SearchRequest;
use Relevance\Data\SearchResponse;
use Relevance\Facades\Relevance;
use Relevance\Filters\Filter;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\IndexRegistry;
use Relevance\Search\Hydration\EloquentHydrator;

class SearchBuilder
{
    private string $indexName;
    /** @var class-string<Model>|null */
    private ?string $modelClass = null;
    private ?IndexDefinition $indexDefinition = null;

    private string $query = '';
    /** @var list<Filter> */
    private array $filters = [];
    /** @var array<string, 'asc'|'desc'> */
    private array $sort = [];
    /** @var list<string> */
    private array $facets = [];
    private SearchMode $mode = SearchMode::Hybrid;
    private ?float $semanticRatio = null;
    private ?string $profile = null;
    private SearchContext $context;
    private int $limit = 20;
    private int $offset = 0;
    private ?string $cursor = null;
    /** @var array<string, mixed> */
    private array $options = [];

    /**
     * @param class-string<Model>|string|IndexDefinition $target
     */
    public function __construct(string|IndexDefinition $target)
    {
        $this->context = new SearchContext();

        if ($target instanceof IndexDefinition) {
            $this->indexName = $target->name();
            $this->indexDefinition = $target;
        } elseif (is_subclass_of($target, Model::class)) {
            $this->modelClass = $target;
            /** @var IndexRegistry $registry */
            $registry = app(IndexRegistry::class);
            $def = $registry->forModel($target);
            $this->indexDefinition = $def;
            $this->indexName = $def ? $def->name() : (new $target())->getTable();
        } else {
            $this->indexName = $target;
            /** @var IndexRegistry $registry */
            $registry = app(IndexRegistry::class);
            $this->indexDefinition = $registry->get($target);
        }
    }

    public function query(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function search(string $query): self
    {
        return $this->query($query);
    }

    public function where(string $field, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->filters[] = match ($operator) {
            '='  => Filter::eq($field, $value),
            '>'  => Filter::range($field, gt: $value),
            '>=' => Filter::range($field, gte: $value),
            '<'  => Filter::range($field, lt: $value),
            '<=' => Filter::range($field, lte: $value),
            '!=' => Filter::not(Filter::eq($field, $value)),
            default => Filter::eq($field, $value),
        };

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $field, array $values): self
    {
        $this->filters[] = Filter::in($field, $values);
        return $this;
    }

    public function whereBetween(string $field, mixed $min, mixed $max): self
    {
        $this->filters[] = Filter::range($field, gte: $min, lte: $max);
        return $this;
    }

    public function near(float $lat, float $lng, float $radiusKm = 5.0, string $field = 'location'): self
    {
        $this->filters[] = Filter::geoRadius($field, $lat, $lng, $radiusKm * 1000.0);
        return $this;
    }

    public function facets(string ...$fields): self
    {
        $this->facets = array_values($fields);
        return $this;
    }

    public function sortBy(string $field, string $direction = 'asc'): self
    {
        $this->sort[$field] = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        return $this;
    }

    public function mode(SearchMode|string $mode): self
    {
        $this->mode = is_string($mode) ? (SearchMode::tryFrom($mode) ?? SearchMode::Hybrid) : $mode;
        return $this;
    }

    public function semanticRatio(float $ratio): self
    {
        $this->semanticRatio = max(0.0, min(1.0, $ratio));
        return $this;
    }

    public function profile(string $profile): self
    {
        $this->profile = $profile;
        return $this;
    }

    public function for(mixed $user): self
    {
        $userId = null;
        $tenantId = null;

        if (is_object($user)) {
            if (method_exists($user, 'getKey')) {
                $userId = (string) $user->getKey();
            }
            if (isset($user->tenant_id)) {
                $tenantId = (string) $user->tenant_id;
            }
        } elseif (is_string($user) || is_int($user)) {
            $userId = (string) $user;
        }

        $this->context = new SearchContext(
            tenant: $tenantId ?? $this->context->tenant,
            userId: $userId ?? $this->context->userId,
            acl: $this->context->acl,
        );

        return $this;
    }

    public function tenant(?string $tenantId): self
    {
        $this->context = new SearchContext(
            tenant: $tenantId,
            userId: $this->context->userId,
            acl: $this->context->acl,
        );

        return $this;
    }

    public function withoutScopes(?string $reason = null): self
    {
        $this->options['without_scopes'] = true;
        if ($reason !== null) {
            $this->options['without_scopes_reason'] = $reason;
        }

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function cursor(?string $cursor): self
    {
        $this->cursor = $cursor;
        return $this;
    }

    public function indexDefinition(): ?IndexDefinition
    {
        return $this->indexDefinition;
    }

    public function buildRequest(): SearchRequest
    {
        $filter = null;
        if (!empty($this->filters)) {
            $filter = count($this->filters) === 1 ? $this->filters[0] : Filter::all(...$this->filters);
        }

        $options = array_merge($this->options, ['offset' => $this->offset]);

        return new SearchRequest(
            index: $this->indexName,
            query: $this->query,
            filter: $filter,
            sort: $this->sort,
            limit: $this->limit,
            cursor: $this->cursor,
            facets: $this->facets,
            mode: $this->mode,
            semanticRatio: $this->semanticRatio,
            profile: $this->profile,
            context: $this->context,
            options: $options,
        );
    }

    public function get(): SearchResponse
    {
        return Relevance::search($this->buildRequest(), $this->indexName);
    }

    public function explain(): SearchExplainResult
    {
        return Relevance::explain($this->buildRequest(), $this->indexName);
    }

    /**
     * @return EloquentCollection<int, Model>
     */
    public function models(): EloquentCollection
    {
        $response = $this->get();
        if ($this->modelClass === null) {
            return new EloquentCollection();
        }

        $hydrator = new EloquentHydrator();
        return $hydrator->hydrate($this->modelClass, $response->hits, index: $this->indexName);
    }

    /**
     * @return LengthAwarePaginatorContract<int, mixed>
     */
    public function paginate(int $perPage = 20, string $pageName = 'page', ?int $page = null): LengthAwarePaginatorContract
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $this->limit($perPage)->offset(($page - 1) * $perPage);

        $response = $this->get();

        $items = $this->modelClass !== null
            ? (new EloquentHydrator())->hydrate($this->modelClass, $response->hits, index: $this->indexName)
            : collect($response->hits);

        return new LengthAwarePaginator(
            items: $items,
            total: $response->total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }
}
