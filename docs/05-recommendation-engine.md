# 5. Recommendations Engine & Collaborative Filtering

Laravel Relevance features a complete recommendation engine built directly into your application, supporting Item-kNN collaborative filtering, popularity signals, real-time trending, and exploration slots.

---

## 5.1 Placements

A **Placement** is a named recommendation location on your website or app (e.g. `home.for_you`, `pdp.similar`, `cart.cross_sell`).

### Registering Placements

In your service provider or boot configuration:

```php
use Relevance\Facades\Relevance;
use Relevance\Recommendations\Data\PlacementDefinition;

Relevance::placements()->register(new PlacementDefinition(
    name: 'pdp.similar',
    generators: [
        'item_knn'        => 0.60, // 60% weight to co-interaction similarity
        'content_similar' => 0.40, // 40% weight to vector/category similarity
    ],
    filters: [
        'already_purchased',
        'out_of_stock',
        'dismissed',
    ],
    exploration: 0.15, // 15% exploration slots for fresh catalog discovery
    limit: 8,
));
```

### Serving Recommendations

```php
use Relevance\Facades\Relevance;

$result = Relevance::placement('pdp.similar')
    ->for($user)
    ->item($currentProduct)
    ->get();

foreach ($result->items as $item) {
    echo "Recommended Item ID: {$item->id}, Score: {$item->score}\n";
}
```

---

## 5.2 Item-kNN Collaborative Filtering

The Item-kNN engine discovers product affinities from real user interactions (views, clicks, purchases) using **shrunk cosine similarity**:

$$\text{similarity}(i, j) = \frac{\sum_{u} w_{ui} \cdot w_{uj}}{\sqrt{\sum_u w_{ui}^2} \cdot \sqrt{\sum_u w_{uj}^2} + \lambda}$$

The shrinkage parameter ($\lambda = 20$) dampens spurious pairs with low co-occurrence counts.

### Training Item Neighbors

Train similarity pairs directly from recorded interactions:

```bash
php artisan relevance:rec:train --index=products --min-support=3 --shrinkage=20
```

This populates `relevance_item_neighbors` with precomputed, high-affinity neighbor scores.

---

## 5.3 Event Tracking Pipeline

Track user actions seamlessly to power recommendations and relevance feedback loops:

```php
use Relevance\Facades\Relevance;

// Track a click from search results
Relevance::track($user)->click($product, searchId: $response->meta['search_id'] ?? null, position: 2);

// Track product view
Relevance::track($user)->view($product);

// Track cart addition
Relevance::track($user)->addToCart($product, value: 89.99);

// Track completed purchase
Relevance::track($user)->purchase($product, value: 89.99);

// Anonymous user tracking with later merge on login
Relevance::track()->anon($anonymousSessionId)->view($product);
Relevance::alias($anonymousSessionId, $user); // Merges interaction history upon login
```

---

## 5.4 Eloquent Shortcuts

Searchable models equipped with the `Searchable` trait expose convenient recommendation shortcuts:

```php
// Similar products (Content vector + Item-kNN neighbors)
$similarProducts = $product->similar(limit: 6);

// "Customers who bought this also bought"
$alsoBought = Product::alsoInteracted($product, 'purchase')->limit(5)->get();

// Personalized recommendations for a user
$forUser = Product::recommendFor($user)->limit(10)->get();

// Trending products over the last 24 hours
$trending = Product::trending(window: '24h', segment: 'electronics')->limit(8)->get();
```
