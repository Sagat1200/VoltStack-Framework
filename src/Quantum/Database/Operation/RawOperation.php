<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

/**
 * Operación Raw. SQL sin validar.
 *
 * @psalm-immutable
 */
final readonly class RawOperation implements DatabaseOperationInterface
{
    /** @param list<mixed> $params bindings posicionales 0-based */
    public function __construct(
        public OperationKind $kind,
        public string $sql,
        public array $params = [],
        public ?string $comment = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function kind(): OperationKind
    {
        return $this->kind;
    }

    public function describe(): string
    {
        $snippet = strlen($this->sql) > 80 ? substr($this->sql, 0, 77) . '...' : $this->sql;
        $idempotency = $this->idempotencyKey !== null && trim($this->idempotencyKey) !== ''
            ? ' idempotency=yes'
            : '';

        return sprintf('[%s] %s (%d params%s)', $this->kind->value, $snippet, count($this->params), $idempotency);
    }
}
