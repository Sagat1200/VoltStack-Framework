<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

final readonly class EntityKey
{
    public function __construct(
        public string $entityClass,
        public int|string $identifier,
    ) {
    }

    public function hash(): string
    {
        return $this->entityClass . ':' . (is_int($this->identifier) ? 'i:' : 's:') . (string) $this->identifier;
    }
}
