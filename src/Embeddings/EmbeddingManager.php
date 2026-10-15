<?php

declare(strict_types=1);

namespace Relevance\Embeddings;

use Closure;
use InvalidArgumentException;
use Relevance\Contracts\EmbeddingProvider;
use Relevance\Data\EmbedKind;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\VectorField;
use RuntimeException;

class EmbeddingManager
{
    /** @var array<string, EmbeddingProvider> */
    private array $providers = [];

    /** @var array<string, Closure(): EmbeddingProvider> */
    private array $customCreators = [];

    public function __construct(
        private readonly ?EmbeddingCache $cache = null,
        private readonly ?TokenBudgetGuard $budgetGuard = null,
        private readonly string $defaultProvider = 'fake',
    ) {}

    public function provider(?string $name = null): EmbeddingProvider
    {
        $name = $name ?? $this->defaultProvider;

        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        return $this->providers[$name] = $this->createProvider($name);
    }

    public function extend(string $name, Closure $callback): self
    {
        $this->customCreators[$name] = $callback;
        unset($this->providers[$name]);

        return $this;
    }

    public function set(string $name, EmbeddingProvider $provider): self
    {
        $this->providers[$name] = $provider;

        return $this;
    }

    /**
     * Embed a single text with cache lookup.
     *
     * @return list<float>
     */
    public function embed(string $text, ?string $providerName = null, EmbedKind $kind = EmbedKind::Document): array
    {
        $results = $this->embedMany([$text], $providerName, $kind);
        return $results[0] ?? [];
    }

    /**
     * Embed multiple texts with cache lookup.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts, ?string $providerName = null, EmbedKind $kind = EmbedKind::Document): array
    {
        if (empty($texts)) {
            return [];
        }

        $provider = $this->provider($providerName);
        $fingerprint = $provider->fingerprint();

        $cached = $this->cache !== null
            ? $this->cache->getMany($fingerprint, $texts)
            : [];

        $missing = [];
        foreach ($texts as $text) {
            if (!isset($cached[$text])) {
                $missing[] = $text;
            }
        }

        if (!empty($missing)) {
            if ($this->budgetGuard !== null && $this->budgetGuard->isExhausted()) {
                throw new RuntimeException("Embedding daily token budget exhausted.");
            }

            $computed = $provider->embed($missing, $kind);

            $toCache = [];
            foreach ($missing as $idx => $text) {
                if (isset($computed[$idx])) {
                    $cached[$text] = $computed[$idx];
                    $toCache[$text] = $computed[$idx];
                }
            }

            if ($this->cache !== null && !empty($toCache)) {
                $this->cache->putMany($fingerprint, $toCache);
            }

            if ($this->budgetGuard !== null) {
                // Approximate 1 token per 4 chars
                $estimatedTokens = (int) array_sum(array_map(static fn ($t) => max(1, (int) (strlen($t) / 4)), $missing));
                $this->budgetGuard->spend($estimatedTokens);
            }
        }

        $ordered = [];
        foreach ($texts as $text) {
            $ordered[] = $cached[$text] ?? [];
        }

        return $ordered;
    }

    public function fingerprintFor(IndexDefinition $def): string
    {
        $schema = $def->compiledSchema();
        foreach ($schema->fields as $fieldData) {
            if (is_array($fieldData) && ($fieldData['type'] ?? '') === 'vector') {
                $provider = (string) ($fieldData['provider'] ?? 'default');
                return $this->provider($provider === 'default' ? null : $provider)->fingerprint();
            }
        }

        return $this->provider()->fingerprint();
    }

    private function createProvider(string $name): EmbeddingProvider
    {
        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])();
        }

        $config = config("relevance.embeddings.providers.{$name}", []);

        return match ($name) {
            'fake' => new FakeEmbeddingProvider(
                dimensions: (int) ($config['dimensions'] ?? 8)
            ),
            'openai' => new OpenAIEmbeddingProvider(
                apiKey: (string) ($config['key'] ?? ''),
                model: (string) ($config['model'] ?? 'text-embedding-3-small'),
                dimensions: (int) ($config['dimensions'] ?? 1536),
                batchSize: (int) ($config['batch'] ?? 64),
                allowPii: (bool) ($config['allow_pii'] ?? false),
            ),
            'http', 'local' => new HttpEmbeddingProvider(
                url: (string) ($config['url'] ?? 'http://localhost:8000/embed'),
                model: (string) ($config['model'] ?? 'local-model'),
                dimensions: (int) ($config['dimensions'] ?? 1024),
            ),
            default => throw new InvalidArgumentException("Unsupported embedding provider [{$name}]."),
        };
    }
}
