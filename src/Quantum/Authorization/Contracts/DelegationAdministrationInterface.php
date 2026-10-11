<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;

interface DelegationAdministrationInterface
{
    /**
     * @param array{trustee_id?:string,grantor_id?:string,scope?:string|Scope|null,type?:string,value?:string,include_expired?:bool,include_revoked?:bool} $filters
     *
     * @return list<array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string,granted_at:string|null,expires_at:string|null,revoked_at:string|null}>
     */
    public function listDelegations(array $filters = []): array;

    public function grantDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
        ?string $expiresAt = null,
    ): bool;

    public function revokeDelegation(
        string $trusteeId,
        string $grantorId,
        Role|Permission $grant,
        Scope|string $scope = Scope::GLOBAL,
    ): bool;

    /**
     * Revoca todos los grants de delegacion que coincidan con los filtros indicados.
     *
     * Al menos uno de trusteeId o grantorId debe ser provisto; si ambos son null,
     * el metodo es no-op (retorna 0 sin mutar ni invalidar consistencia).
     *
     * @return int Cantidad de grants revocados.
     */
    public function revokeAllDelegations(
        ?string $trusteeId = null,
        ?string $grantorId = null,
        Scope|string|null $scope = null,
    ): int;

    /**
     * Revoca grants de delegacion que cumplen un predicado.
     *
     * El predicado recibe el grant como array (shape de toArray) y debe
     * retornar bool. Los grants que cumplen el predicado son revocados
     * (soft-delete: revoked_at = now). Un solo bump de consistencia con
     * reason delegation.revoke_policy se emite si count > 0.
     *
     * @param callable(array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string,granted_at:string|null,expires_at:string|null,revoked_at:string|null}): bool $predicate
     * @return int Cantidad de grants revocados.
     */
    public function revokeDelegationsByPolicy(callable $predicate, ?string $reason = null): int;
}
