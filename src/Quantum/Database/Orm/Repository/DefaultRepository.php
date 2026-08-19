<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Repository;

/**
 * Placeholder DefaultRepository class.
 *
 * V1: clase concreta simple. Las entidades customizadas pueden definir
 * repositoryClass = MiRepo::class; heredan de DefaultRepository.
 *
 * El resto (find/findBy/findAll/persist/flush) se implementa en F4/DDD-08
 * (EntityManager + Repository Contract) ya que necesita Mapping+UoW.
 *
 * @template T of object
 */
class DefaultRepository
{
    /** @param class-string<T> $entityClass */
    public function __construct(
        protected readonly string $entityClass,
    ) {}

    /** @return class-string<T> */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }
}
