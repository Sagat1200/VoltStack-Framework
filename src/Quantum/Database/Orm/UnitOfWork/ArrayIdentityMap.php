<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork;

use Quantum\Database\Orm\UnitOfWork\Enum\EntityState;
use Quantum\Database\Orm\UnitOfWork\Exception\OrmException;

/**
 * Implementación por defecto del IdentityMap (in-memory, array).
 */
final class ArrayIdentityMap implements IdentityMapInterface
{
    /**
     * Estructura: [entityClass][idHash] => [entity, state]
     *
     * @var array<class-string,array<string,array{0:object,1:EntityState}>>
     */
    private array $map = [];

    public function has(string $entityClass, string $idHash): bool
    {
        return isset($this->map[$entityClass][$idHash]);
    }

    public function get(string $entityClass, string $idHash): object
    {
        $e = $this->tryGet($entityClass, $idHash);
        if ($e === null) {
            throw new OrmException(
                "Entidad no encontrada en IM: {$entityClass}#{$idHash}",
                'ORM_2101',
            );
        }
        return $e;
    }

    public function tryGet(string $entityClass, string $idHash): ?object
    {
        return $this->map[$entityClass][$idHash][0] ?? null;
    }

    public function set(
        string $entityClass,
        string $idHash,
        object $entity,
        EntityState $initialState = EntityState::MANAGED,
    ): void {
        if ($idHash === '') {
            return; // no indexar entities sin id (NEW aun)
        }
        $this->map[$entityClass][$idHash] = [$entity, $initialState];
    }

    public function remove(string $entityClass, string $idHash): void
    {
        unset($this->map[$entityClass][$idHash]);
        if (isset($this->map[$entityClass]) && count($this->map[$entityClass]) === 0) {
            unset($this->map[$entityClass]);
        }
    }

    public function detach(string $entityClass, string $idHash): void
    {
        $this->remove($entityClass, $idHash);
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->map as $byId) {
            foreach ($byId as $item) {
                $out[] = $item[0];
            }
        }
        return $out;
    }

    public function allWithState(): array
    {
        $out = [];
        foreach ($this->map as $cls => $byId) {
            foreach ($byId as $id => $item) {
                $out[] = [
                    'class'  => $cls,
                    'id'     => $id,
                    'entity' => $item[0],
                    'state'  => $item[1],
                ];
            }
        }
        return $out;
    }

    public function stateOf(string $entityClass, string $idHash): ?EntityState
    {
        return $this->map[$entityClass][$idHash][1] ?? null;
    }

    public function setState(string $entityClass, string $idHash, EntityState $state): void
    {
        if (!isset($this->map[$entityClass][$idHash])) {
            return;
        }
        $this->map[$entityClass][$idHash][1] = $state;
    }

    public function clear(?string $entityClass = null): void
    {
        if ($entityClass === null) {
            $this->map = [];
            return;
        }
        unset($this->map[$entityClass]);
    }

    public function count(): int
    {
        $n = 0;
        foreach ($this->map as $byId) {
            $n += count($byId);
        }
        return $n;
    }
}
