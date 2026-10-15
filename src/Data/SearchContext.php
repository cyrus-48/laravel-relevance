<?php

declare(strict_types=1);

namespace Relevance\Data;

use Relevance\Support\Deadline;

final readonly class SearchContext
{
    /**
     * @param list<string> $acl
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public ?string $tenant = null,
        public ?string $locale = null,
        public ?string $userId = null,
        public ?string $anonId = null,
        public array $acl = ['public'],
        public ?string $sessionId = null,
        public ?string $experimentAssignment = null,
        public ?string $requestId = null,
        public ?string $device = null,
        public ?string $region = null,
        public ?Deadline $deadline = null,
        public array $extra = [],
    ) {}

    public function withDeadline(Deadline $deadline): self
    {
        return new self(
            tenant: $this->tenant,
            locale: $this->locale,
            userId: $this->userId,
            anonId: $this->anonId,
            acl: $this->acl,
            sessionId: $this->sessionId,
            experimentAssignment: $this->experimentAssignment,
            requestId: $this->requestId,
            device: $this->device,
            region: $this->region,
            deadline: $deadline,
            extra: $this->extra,
        );
    }
}
