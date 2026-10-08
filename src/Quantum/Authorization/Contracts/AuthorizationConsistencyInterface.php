<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Scope;

interface AuthorizationConsistencyInterface
{
    public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string;

    public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string;

    /**
     * @return array<string, string>
     */
    public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null): array;

    /**
     * @return array<string, string>
     */
    public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null): array;
}
