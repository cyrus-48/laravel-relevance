<?php

declare(strict_types=1);

namespace Relevance\Events;

use Relevance\Support\Privacy\PrivacyErasureReport;

final readonly class UserPrivacyErased
{
    public function __construct(
        public PrivacyErasureReport $report,
    ) {}
}
