<?php

declare(strict_types=1);

namespace Quantum\Metadata\Schema;

use Quantum\Metadata\MetadataMergeStrategy;
use Quantum\Metadata\MetadataValueType;

final readonly class MetadataSchema
{
    /**
     * @param array<int, string> $scopes
     */
    public function __construct(
        public string $key,
        public MetadataValueType $type = MetadataValueType::Mixed,
        public MetadataMergeStrategy $merge = MetadataMergeStrategy::Replace,
        public mixed $defaultValue = null,
        public bool $inheritable = true,
        public bool $repeatable = false,
        public bool $final = false,
        public array $scopes = [],
    ) {
    }
}

