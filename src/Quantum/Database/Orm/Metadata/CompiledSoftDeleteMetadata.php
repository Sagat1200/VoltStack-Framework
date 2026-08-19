<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/** @internal */
final readonly class CompiledSoftDeleteMetadata
{
    public function __construct(
        public string $propertyName,
        public string $columnName,
    ) {}
}
