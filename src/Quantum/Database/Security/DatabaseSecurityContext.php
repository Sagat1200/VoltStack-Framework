<?php declare(strict_types=1);

namespace Quantum\Database\Security;

/**
 * Contexto de seguridad portátil del subsistema Database.
 * No guarda objetos; todos sus campos son valores serializables seguros.
 */
final readonly class DatabaseSecurityContext
{
    /**
     * @param list<string> $roles
     * @param array<string,bool> $policies
     */
    public function __construct(
        public ?string $subjectId = null,
        public array $roles = [],
        public array $policies = [],
        public bool $redactSensitive = true,
    ) {}

    public function policyEnabled(string $name): bool
    {
        return (bool)($this->policies[$name] ?? false);
    }
}
