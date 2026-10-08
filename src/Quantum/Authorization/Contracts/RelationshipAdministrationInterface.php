<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Scope;

interface RelationshipAdministrationInterface
{
    /**
     * @param array{principal_id?:string,relation?:string,scope?:string|Scope|null} $filters
     * @return list<array{principal_id:string,relation:string,resource_key:string,scope:string}>
     */
    public function listRelationships(array $filters = []): array;

    public function revokeRelationshipByKey(
        string $principalId,
        string $relation,
        string $resourceKey,
        Scope|string $scope = Scope::GLOBAL,
    ): bool;
}
