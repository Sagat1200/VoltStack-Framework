<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

/**
 * Representa un role como colección nombrada de Permissions.
 *
 * Un Role es contenedor semántico de grants. El modelo deliberadamente no
 * incluye herencia en esta primera versión (la jerarquía organiza Scopes).
 * Los permissions se normalizan en un array indexado por nombre para
 * deduplicar automáticamente.
 */
final readonly class Role
{
    /** @var array<string, Permission> */
    public array $permissions;

    /**
     * @param iterable<Permission|string> $permissions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $name,
        iterable $permissions = [],
        public ?string $description = null,
        public array $metadata = [],
    ) {
        $normalized = [];

        foreach ($permissions as $permission) {
            $permissionObject = $permission instanceof Permission
                ? $permission
                : Permission::from((string) $permission);

            $normalized[$permissionObject->name] = $permissionObject;
        }

        $this->permissions = $normalized;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values(array_map(
            static fn (Permission $permission): string => $permission->name,
            $this->permissions,
        ));
    }

    public function grantsPermission(Permission|string $permission): bool
    {
        $permissionObject = $permission instanceof Permission
            ? $permission
            : Permission::from($permission);

        return isset($this->permissions[$permissionObject->name]);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->name, $other->name) === 0;
    }

    /**
     * @return array{name:string,description:string|null,permissions:list<string>,metadata:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissionNames(),
            'metadata' => $this->metadata,
        ];
    }
}
