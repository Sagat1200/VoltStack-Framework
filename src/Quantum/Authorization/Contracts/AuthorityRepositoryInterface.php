<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\PrincipalResolverInterface;

/**
 * Repositorio de autoridad (Authority). Resuelve grants de roles, scopes y
 * permissions asociados a un principal concreto (y opcionalmente un tenant/scope).
 *
 * El repositorio opera sobre identificadores string de principal y scope. No
 * se acopla al tipo concreto de Principal, sino que recibe `string $principalId`.
 *
 * El modelo es additive: todo resultado se normaliza a una lista plana de
 * permissions efectivas para el principal (union de roles + grants directos).
 */
interface AuthorityRepositoryInterface
{
    /**
     * @return list<Role> Roles directos asignados al principal dentro del scope.
     */
    public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array;

    /**
     * @return list<Permission> Permissions directas (fuera de roles) asignadas.
     */
    public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array;

    /**
     * @return list<Permission> Permissions efectivas = union por roles + directas,
     *                          heredadas hacia arriba en la jerarquia de scopes.
     */
    public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array;

    /**
     * @return list<Scope> Scopes en los que el principal cuenta con al menos un grant.
     */
    public function scopesForPrincipal(string $principalId): array;

    /**
     * Indica si el principal tiene el permiso o role dado en el scope.
     */
    public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool;
}
