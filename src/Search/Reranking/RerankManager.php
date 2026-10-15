<?php

declare(strict_types=1);

namespace Relevance\Search\Reranking;

use Relevance\Contracts\Reranker;
use Relevance\Testing\FakeReranker;

class RerankManager
{
    /** @var array<string, Reranker> */
    private array $rerankers = [];

    /**
     * @param array<string, array<string, mixed>> $configRerankers
     */
    public function __construct(array $configRerankers = [])
    {
        foreach ($configRerankers as $name => $cfg) {
            $driver = $cfg['driver'] ?? 'http';
            if ($driver === 'http') {
                $this->rerankers[$name] = new HttpReranker(
                    url: (string) ($cfg['url'] ?? ''),
                    apiKey: isset($cfg['key']) ? (string) $cfg['key'] : null,
                    model: (string) ($cfg['model'] ?? 'rerank-v3.5'),
                    timeoutMs: (int) ($cfg['timeout_ms'] ?? 500),
                );
            }
        }
    }

    public function register(string $name, Reranker $reranker): self
    {
        $this->rerankers[$name] = $reranker;
        return $this;
    }

    public function get(string $name): ?Reranker
    {
        if (isset($this->rerankers[$name])) {
            return $this->rerankers[$name];
        }

        if ($name === 'fake') {
            return $this->rerankers['fake'] ??= new FakeReranker();
        }

        return null;
    }
}
