<?php

declare(strict_types=1);

namespace Relevance\Data;

enum SearchMode: string
{
    case Text = 'text';
    case Vector = 'vector';
    case Hybrid = 'hybrid';
}
