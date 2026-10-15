# 3. Search, Hybrid Retrieval & Vectors

## 3.1 Search Queries & Eloquent Hydration

Execute queries via Eloquent models or directly through the `Relevance` facade.

### Model-First Search

```php
use App\Models\Product;

$paginator = Product::relevance('ergonomic chair')
    ->where('in_stock', true)
    ->where('price', '<=', 500)
    ->facets('brand', 'category')
    ->sortBy('price', 'asc')
    ->paginate(20);

// Models are fully hydrated in exact search-ranked order
$models = $paginator->items();
```

### Index-First Search

```php
use Relevance\Facades\Relevance;

$response = Relevance::index('products')
    ->search('ultrawide monitor')
    ->where('in_stock', true)
    ->limit(10)
    ->get();

foreach ($response->hits as $hit) {
    echo "ID: {$hit->id}, Score: {$hit->score}\n";
    print_r($hit->fields);
    print_r($hit->highlights);
}
```

---

## 3.2 Hybrid Search (Lexical + Dense Vector)

Lexical search (BM25) excels at exact matches (model numbers, SKU codes, unique names), whereas Dense Vector retrieval captures conceptual intent and semantic synonyms.

Laravel Relevance unifies both through **Reciprocal Rank Fusion (RRF)**:

$$RRF(d) = \sum_{m \in M} w_m \cdot \frac{1}{k + r_m(d)}$$

where $k = 60$, $r_m(d)$ is the document's rank in mode $m$, and $w_m$ is the mode's relative weight.

```php
$response = Relevance::index('products')
    ->search('best lightweight running jacket for winter rain')
    ->mode('hybrid')
    ->semanticRatio(0.70) // 70% vector semantic weight, 30% lexical text weight
    ->get();
```

### Retrieval Modes

- `->mode('text')`: Pure keyword lexical matching.
- `->mode('vector')`: Pure dense vector cosine/dot-product similarity.
- `->mode('hybrid')`: Reciprocal rank fusion of text and vector lists.

---

## 3.3 Fail-Soft Degradation

When external vector embedding APIs (e.g. OpenAI) fail or time out, search continues without interruption:

1. The query embedding step is caught.
2. The search automatically falls back to full-text lexical matching.
3. The response logs `degraded: ['embedding']`.

```php
$response = Relevance::index('products')->search('laptop')->get();

if (in_array('embedding', $response->degraded, true)) {
    // Search served lexical results seamlessly despite external provider outage
}
```

---

## 3.4 Facets & Disjunctive Filtering

Facets are computed automatically during retrieval:

```php
$response = Relevance::index('products')
    ->search('headphones')
    ->facets('brand', 'color')
    ->get();

$brandFacets = $response->facets['brand'];
// [
//     ['value' => 'Sony', 'count' => 14],
//     ['value' => 'Bose', 'count' => 9],
// ]
```
