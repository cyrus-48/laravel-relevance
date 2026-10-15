<?php

declare(strict_types=1);

namespace Relevance\Exceptions;

use RuntimeException;

class MissingTenantScopeException extends RuntimeException
{
    public function __construct(string $message = 'Tenancy is required but no tenant scope was provided.')
    {
        parent::__construct($message);
    }
}
