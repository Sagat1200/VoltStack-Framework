<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Scope;

interface RelationshipRepositoryInterface
{
    public function hasRelationship(
        string $principalId,
        string $relation,
        mixed $resource,
        Scope|string $scope = Scope::GLOBAL,
    ): bool;
}
