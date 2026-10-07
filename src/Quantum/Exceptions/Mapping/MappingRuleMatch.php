<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Mapping;

final readonly class MappingRuleMatch
{
    public function __construct(
        public MappingRule $rule,
        public int $specificityRank,
        public int $distance,
    ) {
    }
}
