# Laravel Relevance

[![Latest Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/acme/laravel-relevance)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-777bb4.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E11%20%7C%20%5E12%20%7C%20%5E13-ff2d20.svg)](https://laravel.com)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208%20clean-brightgreen.svg)](https://phpstan.org)
[![Tests Passing](https://img.shields.io/badge/tests-79%20passed%20(314%20assertions)-brightgreen.svg)](https://phpunit.de)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

**Laravel Relevance** is an enterprise-grade search and recommendation orchestration toolkit for Laravel. Rather than being a simple engine wrapper, it provides the end-to-end relevance engineering pipeline: transactional outbox indexing, zero-downtime reindexing, vector embeddings, hybrid Reciprocal Rank Fusion (RRF), query rules, business signal boosts, recommendation placements with collaborative filtering, A/B experiments, offline relevance evaluation gates (NDCG@10), cross-encoder rerankers, diversity field collapse, federated multi-search, RAG chunk retrieval, and GDPR privacy tooling.

---

## Table of Contents

- [Architectural Highlights](#architectural-highlights)
- [Quickstart (5 Minutes)](#quickstart-5-minutes)
  - [1. Installation & Publishing](#1-installation--publishing)
  - [2. Define an Index](#2-define-an-index)
  - [3. Make Models Searchable](#3-make-models-searchable)
  - [4. Search with Eloquent Hydration](#4-search-with-eloquent-hydration)
- [Core Capabilities](#core-capabilities)
  - [Hybrid Search (Text + Vectors + RRF)](#hybrid-search-text--vectors--rrf)
  - [Relevance Profiles, Boosts & Query Rules](#relevance-profiles-boosts--query-rules)
  - [Recommendations Engine & Placements](#recommendations-engine--placements)
  - [A/B Experiments & Offline Evaluation CI Gate](#ab-experiments--offline-evaluation-ci-gate)
  - [Scale-Out: Cross-Encoder Rerankers & Diversity](#scale-out-cross-encoder-rerankers--diversity)
  - [Federated Multi-Index Search & RAG Retrieval](#federated-multi-index-search--rag-retrieval)
  - [Laravel Scout Compatibility](#laravel-scout-compatibility)
  - [Privacy (GDPR) & Fail-Closed Tenancy](#privacy-gdpr--fail-closed-tenancy)
- [Artisan CLI Commands](#artisan-cli-commands)
- [Detailed Documentation Guides](#detailed-documentation-guides)

---

## Architectural Highlights

- **Fail-Soft Degradation:** When an optional stage (query embedding, cross-encoder reranker, personalizer) times out or experiences an outage, search does **not** fail 500; it degrades gracefully back to candidate ranking, tagging `response->degraded = ['rerank']`.
- **Transactional Outbox:** Model mutations are committed in the same database transaction as the business write. A debounced queue worker drains, coalesces, and vectorizes updates with monotonic version guarantees.
- **Zero-Downtime Generations:** Rebuilding an index writes to a temporary physical generation (`products_g2`) without affecting production reads on alias (`products`). Swapping aliases is atomic.
- **Strict Level 8 Typing:** All internal DTOs are `final readonly`, strictly typed, with 100% PHPStan Level 8 cleanliness.

---

## Quickstart (5 Minutes)

### 1. Installation & Publishing

```bash
composer require acme/laravel-relevance
php artisan relevance:install
php artisan migrate
```

Verify your setup at any time with the built-in diagnostic doctor:

```bash
php artisan relevance:doctor
```

### 2. Define an Index

Generate a declarative index definition:

```bash
php artisan make:relevance-index ProductIndex
```

In `app/Search/ProductIndex.php`:

```php
namespace App\Search;

use Relevance\Indexing\IndexDefinition;
use Relevance\Schema\IndexSchemaBuilder;

class ProductIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'products';
    }

    public function schema(IndexSchemaBuilder $schema): void
    {
        $schema->string('title')->searchable()->weight(2.0);
        $schema->text('description')->searchable();
        $schema->keyword('brand')->filterable()->facet();
        $schema->keyword('category')->filterable()->facet();
        $schema->float('price')->filterable()->sortable();
        $schema->boolean('in_stock')->filterable();
        $schema->vector('embedding', dimensions: 1536)->similarity('cosine');
    }
}
```

Register it in `config/relevance.php`:

```php
'indexes' => [
    \App\Search\ProductIndex::class,
],
```

### 3. Make Models Searchable

Add the `Searchable` trait to your Eloquent model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Relevance\Concerns\Searchable;

class Product extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title'       => $this->title,
            'description' => $this->description,
            'brand'       => $this->brand,
            'category'    => $this->category,
            'price'       => (float) $this->price,
            'in_stock'    => (bool) $this->in_stock,
        ];
    }
}
```

Every `save()`, `update()`, or `delete()` on `Product` now automatically writes an entry to `relevance_outbox` inside the model's database transaction.

### 4. Search with Eloquent Hydration

Execute queries with rich filters, facets, and rank-ordered Eloquent hydration:

```php
use App\Models\Product;
use Relevance\Facades\Relevance;

// Fluent Builder on Model
$products = Product::relevance('wireless noise cancelling headphones')
    ->where('in_stock', true)
    ->where('price', '<', 300)
    ->facets('brand', 'category')
    ->limit(20)
    ->models(); // Returns Collection<Product> in precise rank order

// Or via the Relevance Facade
$response = Relevance::index('products')
    ->search('mechanical keyboard')
    ->where('brand', '=', 'Keychron')
    ->limit(10)
    ->get();

foreach ($response->hits as $hit) {
    echo "ID: {$hit->id}, Score: {$hit->score}\n";
}
```

---

## Core Capabilities

### Hybrid Search (Text + Vectors + RRF)

Combine lexical BM25 matching and semantic dense vectors with reciprocal rank fusion:

```php
$response = Relevance::index('products')
    ->search('running shoes for plantar fasciitis')
    ->mode('hybrid')
    ->semanticRatio(0.65) // 65% vector weight, 35% text weight
    ->get();
```

If the embedding provider is unreachable or times out, the engine automatically degrades to text retrieval without throwing exceptions:
```php
if (in_array('embedding', $response->degraded, true)) {
    // Gracefully handled text fallback
}
```

### Relevance Profiles, Boosts & Query Rules

Configure ranking profiles with business signal boosts and rules:

```php
use Relevance\Facades\Relevance;
use Relevance\Search\Profiles\ProfileDefinition;

Relevance::profiles()->register(new ProfileDefinition(
    name: 'ecommerce_default',
    index: 'products',
    semanticRatio: 0.5,
    boosts: [
        ['type' => 'log', 'field' => 'sales_count', 'weight' => 0.15],
        ['type' => 'decay', 'field' => 'published_at', 'half_life_days' => 14, 'weight' => 0.10],
        ['type' => 'bool', 'field' => 'in_stock', 'weight' => 0.20],
    ],
    boostCap: 0.35, // Ensures business signals never exceed 35% of total score
));

// Merchandising query rules
Relevance::rules()->pin('iphone', ids: ['prod_15_pro_max']);
Relevance::rules()->bury('cheap cables', ids: ['bad_cable_1']);
Relevance::rules()->exclude('adult shoes', ids: ['kids_item_3']);

// Synonyms
Relevance::synonyms()->twoWay('tv', 'television', 'smart tv');
Relevance::synonyms()->oneWay('sneakers', 'kicks');
```

Explain ranking step-by-step:

```php
$explain = Relevance::explain('laptop', index: 'products');
print_r($explain->trace);
```

### Recommendations Engine & Placements

Register placements combining Item-kNN collaborative filtering, popularity, recently viewed, and exploration slots:

```php
use Relevance\Facades\Relevance;
use Relevance\Recommendations\Data\PlacementDefinition;

Relevance::placements()->register(new PlacementDefinition(
    name: 'home.for_you',
    generators: [
        'item_knn'   => 0.6,
        'popularity' => 0.4,
    ],
    filters: ['already_purchased', 'out_of_stock', 'dismissed'],
    exploration: 0.10, // 10% bandit exploration slots
    limit: 12,
));

// Serve recommendations for user
$recs = Relevance::placement('home.for_you')->for($user)->get();

// Model Shortcuts
$similar = $product->similar(limit: 6);
$forUser = Product::recommendFor($user)->limit(10)->get();
```

### A/B Experiments & Offline Evaluation CI Gate

Evaluate relevance offline using NDCG@10, MRR, Recall@k, and MAP:

```bash
# Run golden query judgments and enforce minimum NDCG@10 gate threshold
php artisan relevance:eval --suite=golden_ecommerce --gate-ndcg=0.65
```

If offline retrieval quality drops below threshold in CI, the command exits with code `1`, preventing deployment of degraded ranking configurations.

Run online A/B experiments:

```php
Relevance::experiments()->create(
    name: 'search_rrf_tuning_v2',
    variants: ['control' => 'profile_v1', 'treatment' => 'profile_v2'],
    weights: ['control' => 50, 'treatment' => 50],
);
```

### Scale-Out: Cross-Encoder Rerankers & Diversity

#### Cross-Encoder Rerankers with Graceful Timeout
```php
Relevance::profiles()->register(new ProfileDefinition(
    name: 'reranked_search',
    index: 'products',
    rerank: [
        'provider'   => 'cohere',
        'top'        => 50,
        'timeout_ms' => 150,
    ],
));
```

#### Diversity & Field Collapse
Avoid brand monopolies in search results (e.g. limit to at most 2 per brand in top results):

```php
Relevance::profiles()->register(new ProfileDefinition(
    name: 'diverse_search',
    index: 'products',
    diversify: [
        'max_per' => ['brand' => 2],
    ],
));
```

#### External Model Artifact Activation Gate
Activate offline-trained ML models (ALS, item2vec, EASE) with automatic validation threshold checking:

```bash
php artisan relevance:artifacts:activate storage/models/item2vec/manifest.json --index=products --min-metric=0.35
```

### Federated Multi-Index Search & RAG Retrieval

#### Federated Search
Query across multiple indices with custom weightings:

```php
$results = Relevance::multi([
    'products' => 1.0,
    'articles' => 0.5,
    'faqs'     => 0.4,
])->query('wireless headphones return policy')->limit(10)->get();

$allHits  = $results->all();
$sections = $results->sections(); // ['products' => SearchResponse, 'articles' => ...]
```

#### RAG Retrieval API
Chunk retrieval with parent document collapse and citation metadata for LLMs:

```php
$rag = Relevance::retrieve('How do I reset my password?')
    ->index('knowledge_base')
    ->chunks()
    ->maxPerParent(2)
    ->limit(8)
    ->get();

foreach ($rag->chunks as $chunk) {
    echo "Content: {$chunk->content}\n";
    echo "Parent ID: {$chunk->parentId}\n";
    echo "Citations: " . json_encode($chunk->citations) . "\n";
}
```

### Laravel Scout Compatibility

Seamlessly switch existing Laravel Scout projects to Relevance by updating `.env`:

```env
SCOUT_DRIVER=relevance
```

`RelevanceScoutEngine` powers standard Scout methods (`search()`, `paginate()`, `raw()`, `import()`, `flush()`) through the underlying engine.

### Privacy (GDPR) & Fail-Closed Tenancy

#### GDPR Right to Erasure & Portability
```php
// Erase all analytics, logs, interactions, and vectors for a subject
$report = Relevance::privacy()->erase($user);
echo "Erased records: {$report->totalDeleted}\n";

// Export all user data for GDPR data access request
$data = Relevance::privacy()->export($user);
```

#### Multi-Tenancy (Fail-Closed)
When `config('relevance.tenancy.required') === true`:
```php
// Must supply tenant on search context, otherwise throws MissingTenantScopeException
$res = Relevance::index('products')
    ->tenant('tenant_42')
    ->search('order #100')
    ->get();

// Admin audited escape hatch
$adminRes = Relevance::index('products')
    ->withoutScopes('admin reconciliation')
    ->search('all data')
    ->get();
```

---

## Artisan CLI Commands

| Command | Description |
|---|---|
| `php artisan relevance:install` | Publish configuration and migration files. |
| `php artisan relevance:doctor` | Run comprehensive health check and driver capability diagnostics. |
| `php artisan make:relevance-index {name}` | Scaffold a new declarative `IndexDefinition` class. |
| `php artisan relevance:sync` | Drain and vectorize pending records in `relevance_outbox`. |
| `php artisan relevance:index:rebuild {index}` | Zero-downtime rebuild into new physical generation. |
| `php artisan relevance:index:swap {index} {generation}` | Atomic alias swap or rollback. |
| `php artisan relevance:explain "{query}"` | Trace full query normalization, rules, and scoring stages. |
| `php artisan relevance:rec:train` | Train item-kNN shrunk cosine similarities. |
| `php artisan relevance:eval --suite={suite}` | Run offline relevance evaluation and enforce NDCG@10 CI gate. |
| `php artisan relevance:benchmark` | Benchmark latency across retrieval modes (text, vector, hybrid). |
| `php artisan relevance:artifacts:activate {manifest}` | Validate and activate external ML model artifacts. |
| `php artisan relevance:privacy:erase {user}` | Permanently delete all subject behavioral data under GDPR. |
| `php artisan relevance:prune` | Clean up interactions, search logs, and events past retention policies. |

---

## Detailed Documentation Guides

For in-depth explanations, configuration options, and architectural blueprints, explore our documentation guides in [`docs/`](docs/):

1. [**Installation & Configuration**](docs/01-installation-and-configuration.md)
2. [**Indexing, Schema & Transactional Outbox**](docs/02-indexing-and-outbox.md)
3. [**Search, Hybrid Retrieval & Vectors**](docs/03-search-and-hybrid-retrieval.md)
4. [**Relevance Controls: Profiles, Boosts, Rules & Synonyms**](docs/04-relevance-profiles-rules-boosts.md)
5. [**Recommendations Engine & Collaborative Filtering**](docs/05-recommendation-engine.md)
6. [**A/B Experiments & Offline Evaluation (NDCG CI Gate)**](docs/06-experiments-and-evaluation.md)
7. [**Scale-Out: Reranking, Diversity, Federated & RAG**](docs/07-advanced-scale-reranking-rag.md)
8. [**Security, Multi-Tenancy & GDPR Privacy**](docs/08-security-privacy-tenancy.md)
9. [**Artisan CLI Command Reference**](docs/09-artisan-cli-reference.md)

---

## Testing & Quality Assurance

Run the test suite:

```bash
composer test
# or
vendor/bin/phpunit
```

Run static analysis:

```bash
composer analyse
# or
vendor/bin/phpstan analyse
```

---

## License

Laravel Relevance is open-sourced software licensed under the [MIT license](LICENSE.md).
