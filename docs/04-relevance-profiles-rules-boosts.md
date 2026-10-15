# 4. Relevance Profiles, Boosts, Rules & Synonyms

Relevance engineering is the art of balancing semantic matching with merchandising rules and business signals (popularity, freshness, margin, ratings).

---

## 4.1 Ranking Profiles

A `ProfileDefinition` bundles ranking configurations, weights, and boosts:

```php
use Relevance\Facades\Relevance;
use Relevance\Search\Profiles\ProfileDefinition;

Relevance::profiles()->register(new ProfileDefinition(
    name: 'ecommerce_sale',
    index: 'products',
    mode: 'hybrid',
    semanticRatio: 0.5,
    boosts: [
        ['type' => 'log', 'field' => 'sales_count', 'weight' => 0.15],
        ['type' => 'decay', 'field' => 'published_at', 'half_life_days' => 14, 'weight' => 0.10],
        ['type' => 'bool', 'field' => 'in_stock', 'weight' => 0.25],
    ],
    boostCap: 0.35, // Caps cumulative boost at 35% of overall score
    budgetMs: 200,
));
```

Execute a search using a specific profile:

```php
$response = Relevance::index('products')
    ->profile('ecommerce_sale')
    ->search('winter coats')
    ->get();
```

---

## 4.2 Business Signal Boosts

Laravel Relevance supports 3 primary boost signal types:

1. **Logarithmic (`log`)**: Ideal for skewed metrics like view counts, ratings, and sales:
   $$\text{boost} = \text{weight} \cdot \ln(1 + \text{value})$$
2. **Decay (`decay`)**: Exponential time decay for catalog freshness:
   $$\text{boost} = \text{weight} \cdot 2^{-\frac{\Delta t}{\text{halfLifeDays}}}$$
3. **Boolean (`bool`)**: Binary signals (e.g. `in_stock`, `is_featured`, `has_free_shipping`).

The `boostCap` property prevents popular items from outranking truly relevant queries (avoiding the "everything matches the top seller" problem).

---

## 4.3 Query Rules (Merchandising)

Query rules enable pre-retrieval and post-retrieval overrides:

```php
// 1. Pin specific items to the top of results
Relevance::rules()->pin('iphone', ids: ['prod_iphone_15_pro']);

// 2. Bury items towards the bottom
Relevance::rules()->bury('cheap accessories', ids: ['refurb_case_3']);

// 3. Exclude items entirely
Relevance::rules()->exclude('winter jackets', ids: ['summer_swimwear_1']);

// 4. Boost specific tags or brands dynamically
Relevance::rules()->boostQuery('running shoes', field: 'brand', value: 'Nike', factor: 1.3);

// 5. Query Redirects (e.g. promo landing pages)
Relevance::rules()->redirect('black friday', url: '/promotions/black-friday');
```

---

## 4.4 Synonyms

Expand vocabulary gaps using directional or reciprocal synonyms:

```php
// Two-Way Synonyms (all terms expand reciprocally)
Relevance::synonyms()->twoWay('couch', 'sofa', 'davenport');
Relevance::synonyms()->twoWay('tv', 'television', 'smart tv');

// One-Way Synonyms (directional expansion)
Relevance::synonyms()->oneWay('sneaker', 'shoe'); // searching "sneaker" finds "shoe", but not vice-versa
```

---

## 4.5 Search Explain Trace

Inspect exact scoring transformations with the `explain` command:

```bash
php artisan relevance:explain "noise cancelling headphones" --index=products
```

Or programmatically in code:

```php
$explain = Relevance::explain('macbook', index: 'products');

print_r($explain->queryUnderstanding); // Normalized terms & synonyms
print_r($explain->rulesApplied);      // Applied rules
print_r($explain->trace);             // Score breakdowns for top hits
```
