<?php

declare(strict_types=1);

namespace Relevance\Contracts;

enum Capability: string
{
    case Facets = 'facets';
    case Geo = 'geo';
    case Highlight = 'highlight';
    case NativeHybrid = 'native_hybrid';
    case Vectors = 'vectors';
    case ExternalVersioning = 'external_versioning';
    case AliasSwap = 'alias_swap';
    case Suggest = 'suggest';
    case Synonyms = 'synonyms';
    case TypoTolerance = 'typo_tolerance';
    case FilterPushdown = 'filter_pushdown';
}
