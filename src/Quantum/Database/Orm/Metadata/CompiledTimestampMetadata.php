<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/** @internal */
final readonly class CompiledTimestampMetadata
{
    public function __construct(
        public ?string $createdAtProp,
        public ?string $updatedAtProp,
    ) {}
}
