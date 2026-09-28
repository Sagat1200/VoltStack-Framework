<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Attributes;

use Attribute;

/**
 * Declares that the annotated class is the custom repository for
 * a given entity class.
 *
 * Usage:
 *
 *     #[RepositoryFor(Article::class)]
 *     final class ArticleRepository extends EntityRepository
 *     {
 *         public function findPublished(): array { ... }
 *     }
 *
 * The `RepositoryFactory` singleton-safe discovery pass resolves
 * all classes carrying this attribute during bootstrap and maps
 * them to their target entity so that `repositoryFor(Article::class)`
 * returns `ArticleRepository` instead of the default `EntityRepository`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RepositoryFor
{
    /**
     * @param class-string $entityClass FQCN of the entity served by the annotated repository.
     */
    public function __construct(
        public readonly string $entityClass,
    ) {
    }
}
