<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * Public MetadataManager interface (fachada consumida por EntityManager, Repo, etc.).
 */
interface MetadataManagerInterface
{
    /**
     * @param class-string $entityClass
     * @return CompiledEntityMetadata
     */
    public function getMetadataFor(string $entityClass): CompiledEntityMetadata;

    /**
     * @return list<class-string>
     */
    public function getAllEntityClasses(): array;

    /**
     * @param iterable<class-string> $entityClasses
     */
    public function warmup(iterable $entityClasses): int;

    public function clearCache(): void;

    /**
     * @return array<class-string,CompiledEntityMetadata>
     */
    public function getAllMetadata(): array;
}
