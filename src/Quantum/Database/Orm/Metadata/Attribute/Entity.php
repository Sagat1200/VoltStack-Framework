<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[Entity] marks a PHP class as ORM-managed. TARGET_CLASS.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Entity
{
    /**
     * @param ?string $table  null → pluralize short class name
     * @param ?string $schema  db schema (PgSQL) o null
     * @param ?class-string $repositoryClass custom Repository o null (default)
     */
    public function __construct(
        public ?string $table = null,
        public ?string $schema = null,
        public ?string $repositoryClass = null,
        public bool $readOnly = false,
    ) {}
}
