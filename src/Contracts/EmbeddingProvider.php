<?php

declare(strict_types=1);

namespace Relevance\Contracts;

use Relevance\Data\EmbedKind;

interface EmbeddingProvider
{
    public function fingerprint(): string;

    public function dimensions(): int;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts, EmbedKind $kind = EmbedKind::Document): array;
}
