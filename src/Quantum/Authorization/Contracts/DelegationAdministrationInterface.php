<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;

interface DelegationAdministrationInterface
{
    /**
     * @param array{trustee_id?:string,grantor_id?:string,scope?:string|Scope|null,type?:string,value?:string} $filters
     *
     * @return list<array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string,granted_at:string|null}>
     */
    public function listDelegations(array $filters = []): array;

    public function grantDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
    ): bool;

    public function revokeDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
    ): bool;
}
