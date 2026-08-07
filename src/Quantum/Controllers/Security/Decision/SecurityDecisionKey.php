<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Decision;

final readonly class SecurityDecisionKey
{
    public function __construct(
        public string $principalId,
        public string $tenantId,
        public string $policyId,
        public string $action,
        public string $resourceIdentity,
        public int $securityContextVersion = 1,
    ) {}

    public function hash(): string
    {
        return sha1(implode('|', [
            $this->principalId,
            $this->tenantId,
            $this->policyId,
            $this->action,
            $this->resourceIdentity,
            $this->securityContextVersion,
        ]));
    }
}
