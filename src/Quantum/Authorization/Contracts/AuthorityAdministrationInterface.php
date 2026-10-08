<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;

interface AuthorityAdministrationInterface
{
    /**
     * @param array{principal_id?:string,scope?:string|Scope|null,type?:string,value?:string} $filters
     * @return list<array{principal_id:string,scope:string,type:string,value:string}>
     */
    public function listGrants(array $filters = []): array;

    public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool;

    public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool;

    public function revokeRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool;

    public function revokePermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool;
}
