# 2. Indexing, Schema & Transactional Outbox

## 2.1 Declarative Index Definitions

Indexes in Laravel Relevance are defined declaratively by extending `IndexDefinition`.

Generate a new index definition:

```bash
php artisan make:relevance-index ArticleIndex
```

In `app/Search/ArticleIndex.php`:

```php
namespace App\Search;

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\Schema;

class ArticleIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'articles';
    }

    public function source(): ?Builder
    {
        return Article::query()->with('author');
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('title')->weight(3.0)->highlight();
        $s->text('content');
        $s->keyword('author')->filterable()->facet();
        $s->keyword('tags')->multiple()->filterable()->facet();
        $s->date('published_at')->filterable()->sortable();
        $s->bool('is_published')->filterable();

        // Dense vector for semantic search, built from the listed fields.
        $s->vector('embedding')->from(['title', 'content']);
    }

    public function toDocument(mixed $item): array
    {
        /** @var Article $item */
        return [
            'id'           => (string) $item->getKey(),
            'title'        => $item->title,
            'content'      => strip_tags((string) $item->content),
            'author'       => $item->author?->name,
            'tags'         => $item->tags->pluck('name')->all(),
            'published_at' => $item->published_at?->getTimestamp(),
            'is_published' => (bool) $item->is_published,
        ];
    }

    public function shouldIndex(mixed $item): bool
    {
        return $item->is_published;
    }
}
```

---

## 2.2 The Transactional Outbox Pattern

Standard search libraries update engines synchronously or dispatch async queue jobs directly from model observers. Under high concurrency or network partitions, this can lead to:
1. **Lost Updates:** A queue job fails or is dropped after the DB transaction commits.
2. **Race Conditions:** Out-of-order writes (e.g. an update job completes after a subsequent delete job).
3. **Ghost Documents:** A document is indexed even if the DB transaction rolled back.

Laravel Relevance solves this with the **Transactional Outbox**:

```
[ Eloquent Model Event ] 
       │
       ▼ (Inside the SAME DB Transaction)
[ INSERT INTO relevance_outbox (doc_id, version, op, status='pending') ]
       │
       ▼ (afterCommit hook)
[ DrainOutbox::dispatch() ]
       │
       ▼ (Queue Worker)
[ Coalesce batch -> Embed -> Upsert into Physical Index Generation ]
```

### Making a Model Searchable

Add the `Searchable` trait and point the model at its index definition:

```php
namespace App\Models;

use App\Search\ArticleIndex;
use Illuminate\Database\Eloquent\Model;
use Relevance\Concerns\Searchable;

class Article extends Model
{
    use Searchable;

    public static function relevanceIndex(): ?string
    {
        return ArticleIndex::class;
    }
}
```

The trait's boot hook attaches the outbox observer automatically; the model does not need to be
listed anywhere else. What gets indexed is whatever `ArticleIndex::toDocument()` returns, and
`ArticleIndex::shouldIndex()` decides whether a save becomes an upsert or a delete.

### Draining the Outbox Manually

You can trigger an immediate outbox drain using Artisan:

```bash
php artisan relevance:sync
```

---

## 2.3 Zero-Downtime Reindexing & Generations

When you update schema definitions or re-embed your corpus, Laravel Relevance guarantees zero search downtime via **Generation Management**:

1. Read queries continuously target the public alias: `articles`.
2. `php artisan relevance:index:rebuild articles` creates a new physical generation (e.g., `articles_g2`).
3. Outbox mutations write to **both** active and building generations.
4. Backfill indexes historical records into `articles_g2`.
5. Once complete, `relevance:index:swap articles 2` atomically flips the alias to `articles_g2`.
6. If an issue is detected, rollback is instant: `relevance:index:swap articles 1`.

```bash
# Rebuild index in the background
php artisan relevance:index:rebuild articles

# Atomically swap alias
php artisan relevance:index:swap articles 2
```
