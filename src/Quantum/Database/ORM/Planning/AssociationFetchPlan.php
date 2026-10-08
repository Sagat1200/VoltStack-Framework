<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Planning;

final readonly class AssociationFetchPlan
{
    /**
     * @param list<string> $preloadPaths
     * @param array<string, list<string>> $joinedNestedPaths
     */
    public function __construct(
        private array $preloadPaths,
        private array $joinedNestedPaths,
    ) {
    }

    /**
     * @return list<string>
     */
    public function preloadPaths(): array
    {
        return $this->preloadPaths;
    }

    /**
     * @return array<string, list<string>>
     */
    public function joinedNestedPaths(): array
    {
        return $this->joinedNestedPaths;
    }
}
