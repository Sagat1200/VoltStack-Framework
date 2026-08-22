<?php

declare(strict_types=1);

namespace Quantum\Database;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Security\DatabaseSecurityContext;
use Quantum\Database\Trace\DatabaseDeadline;
use Quantum\Database\Trace\DatabaseTraceContext;

/**
 * Contexto de Database por request. Inmutable; mutadores devuelven clone.
 */
final readonly class DatabaseContext
{
    public function __construct(
        public string $requestId,
        public ?ConnectionInterface $connection = null,
        public ?string $tenantId = null,
        public ?DatabaseDeadline $deadline = null,
        public ?DatabaseCapabilitySet $capabilities = null,
        public ?DatabaseSecurityContext $security = null,
        public ?DatabaseTraceContext $trace = null,
        public int $maxRows = 1_000_000,
        public int $maxDepth = 16,
    ) {}

    public static function empty(): self
    {
        return new self(
            requestId: bin2hex(random_bytes(8)),
            trace: DatabaseTraceContext::random(),
            security: new DatabaseSecurityContext(),
        );
    }

    // ---------- withXxx (patrón immutable clone) ----------

    public function withConnection(ConnectionInterface $c): self
    {
        return new self(
            requestId: $this->requestId,
            connection: $c,
            tenantId: $this->tenantId,
            deadline: $this->deadline,
            capabilities: $c->getCapabilities(),
            security: $this->security,
            trace: $this->trace,
            maxRows: $this->maxRows,
            maxDepth: $this->maxDepth,
        );
    }

    public function withDeadlineMs(int $timeoutMs): self
    {
        return $this->withDeadline(DatabaseDeadline::fromMs($timeoutMs));
    }

    public function withDeadline(DatabaseDeadline $d): self
    {
        return new self(
            requestId: $this->requestId,
            connection: $this->connection,
            tenantId: $this->tenantId,
            deadline: $d,
            capabilities: $this->capabilities,
            security: $this->security,
            trace: $this->trace,
            maxRows: $this->maxRows,
            maxDepth: $this->maxDepth,
        );
    }

    public function withTenant(?string $tenantId): self
    {
        return new self(
            requestId: $this->requestId,
            connection: $this->connection,
            tenantId: $tenantId,
            deadline: $this->deadline,
            capabilities: $this->capabilities,
            security: $this->security,
            trace: $this->trace,
            maxRows: $this->maxRows,
            maxDepth: $this->maxDepth,
        );
    }

    public function withLimits(?int $maxRows = null, ?int $maxDepth = null): self
    {
        return new self(
            requestId: $this->requestId,
            connection: $this->connection,
            tenantId: $this->tenantId,
            deadline: $this->deadline,
            capabilities: $this->capabilities,
            security: $this->security,
            trace: $this->trace,
            maxRows: $maxRows ?? $this->maxRows,
            maxDepth: $maxDepth ?? $this->maxDepth,
        );
    }

    /**
     * Omite el handle de conexión al serializar para evitar secretos/PDO no serializable.
     *
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        return [
            'requestId' => $this->requestId,
            'tenantId' => $this->tenantId,
            'deadline' => $this->deadline,
            'capabilities' => $this->capabilities,
            'security' => $this->security,
            'trace' => $this->trace,
            'maxRows' => $this->maxRows,
            'maxDepth' => $this->maxDepth,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->requestId = (string) ($data['requestId'] ?? bin2hex(random_bytes(8)));
        $this->connection = null;
        $this->tenantId = isset($data['tenantId']) ? (is_string($data['tenantId']) ? $data['tenantId'] : null) : null;
        $this->deadline = $data['deadline'] instanceof DatabaseDeadline ? $data['deadline'] : null;
        $this->capabilities = $data['capabilities'] instanceof DatabaseCapabilitySet ? $data['capabilities'] : null;
        $this->security = $data['security'] instanceof DatabaseSecurityContext ? $data['security'] : new DatabaseSecurityContext();
        $this->trace = $data['trace'] instanceof DatabaseTraceContext ? $data['trace'] : DatabaseTraceContext::random();
        $this->maxRows = isset($data['maxRows']) ? (int) $data['maxRows'] : 1_000_000;
        $this->maxDepth = isset($data['maxDepth']) ? (int) $data['maxDepth'] : 16;
    }
}
