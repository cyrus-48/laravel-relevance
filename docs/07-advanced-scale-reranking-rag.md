# 7. Scale-Out: Reranking, Diversity, Federated & RAG

Enterprise catalogs require advanced ranking techniques to maximize top-10 click-through rates while ensuring fair brand distribution and multi-index discovery.

---

## 7.1 Cross-Encoder Rerankers

Bi-encoders (dense vector search) retrieve a broad candidate pool ($k = 50–300$), but cannot model full token-to-token cross-attention between query and document. Cross-encoders (such as Cohere Rerank, Jina, or local TEI servers) provide superior accuracy for top candidates.

### Configuring Rerankers

Register an HTTP reranker in `config/relevance.php`:

```php
'search' => [
    'rerankers' => [
        'cohere' => [
            'driver'     => 'http',
            'url'        => 'https://api.cohere.com/v1/rerank',
            'key'        => env('COHERE_API_KEY'),
            'model'      => 'rerank-v3.5',
            'timeout_ms' => 200,
        ],
    ],
],
```

Enable reranking on a ranking profile:

```php
use Relevance\Facades\Relevance;
use Relevance\Search\Profiles\ProfileDefinition;

Relevance::profiles()->register(new ProfileDefinition(
    name: 'ecommerce_reranked',
    index: 'products',
    rerank: [
        'provider'   => 'cohere',
        'top'        => 50,  // Re-rank only the top 50 candidates
        'timeout_ms' => 150, // Strict timeout budget
    ],
));
```

### Timeout Fallback Behavior

If the reranking HTTP request breaches its timeout deadline or throws an exception, the search automatically falls back to candidate order and marks `degraded: ['rerank']` in the response metadata.

---

## 7.2 Diversity & Field Collapse

Avoid mono-brand or mono-category monopolization of search results:

```php
use Relevance\Search\Diversify\FieldCollapser;

$collapser = new FieldCollapser();

// Enforce at most 2 items per brand in the top results
$diversifiedHits = $collapser->collapse($hits, ['brand' => 2]);
```

Configure directly on a search profile:

```php
Relevance::profiles()->register(new ProfileDefinition(
    name: 'diverse_catalog',
    index: 'products',
    diversify: [
        'max_per' => ['brand' => 2],
    ],
));
```

---

## 7.3 External ML Model Activation Gate

For teams training recommendation models externally in Python (ALS, LightFM, EASE, Item2Vec):

1. Export an artifact containing `manifest.json` and `neighbors.jsonl`:

```json
// manifest.json
{
  "algo": "item2vec",
  "model_version": "v2026.10",
  "metrics": {
    "ndcg@10": 0.48
  }
}
```

2. Run the activation gate command:

```bash
php artisan relevance:artifacts:activate storage/models/item2vec/manifest.json --index=products --min-metric=0.40
```

- If offline `ndcg@10 >= 0.40`: imports neighbors and marks model `active`.
- If offline `ndcg@10 < 0.40`: marks artifact `rejected` and aborts import, keeping previous active model.

---

## 7.4 Federated Multi-Index Search

Search across separate indices with individual score weightings and unified pagination:

```php
use Relevance\Facades\Relevance;

$results = Relevance::multi([
    'products'     => 1.0,  // High priority
    'articles'     => 0.6,  // Medium priority
    'help_center'  => 0.4,  // Lower priority
])
->query('wireless headphones warranty and return')
->limit(12)
->get();

// Merged & weighted global hits
$allHits = $results->all();

// Per-index sections for segmented UIs
$sections = $results->sections();
$productHits = $sections['products']->hits;
$articleHits = $sections['articles']->hits;
```

---

## 7.5 RAG Retrieval API (for LLMs)

Retrieve text chunks for Retrieval-Augmented Generation (RAG) pipelines with parent-document collapse:

```php
use Relevance\Facades\Relevance;

$rag = Relevance::retrieve('How do I configure OAuth client credentials?')
    ->index('documentation_chunks')
    ->chunks()
    ->maxPerParent(2) // At most 2 chunks per guide/document
    ->limit(6)
    ->get();

foreach ($rag->chunks as $chunk) {
    echo "Chunk ID: {$chunk->id}\n";
    echo "Parent Document: {$chunk->parentId}\n";
    echo "Content: {$chunk->content}\n";
    echo "Citations: " . json_encode($chunk->citations) . "\n";
}

// Grouped by parent guide
$grouped = $rag->groupedByParent;
```

---

## 7.6 Laravel Scout Engine Adapter

Migrate gradually from Laravel Scout to Laravel Relevance:

In `.env`:

```env
SCOUT_DRIVER=relevance
```

Existing Scout queries automatically run through Relevance:

```php
// Standard Scout API
$products = Product::search('mechanical keyboard')->paginate(15);

// Direct model import / flush
Product::makeAllSearchable();
Product::removeAllFromSearch();
```
