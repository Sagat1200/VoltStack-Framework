<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * @internal
 */
final readonly class CompiledInheritanceMetadata
{
    /**
     * @param array<string,class-string> $map
     */
    public function __construct(
        public string $type,
        public string $discrColumn,
        public array $map,
        public string $discrValue,
    ) {}
}
