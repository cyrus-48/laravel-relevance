<?php

declare(strict_types=1);

namespace Relevance\Data;

enum EmbedKind: string
{
    case Document = 'document';
    case Query = 'query';
}
