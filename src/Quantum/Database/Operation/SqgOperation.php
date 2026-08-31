<?php declare(strict_types=1);

namespace Quantum\Database\Operation;

use Quantum\Database\Operation\Pipeline\QueryOptimizationResult;
use Quantum\Database\Operation\Pipeline\QueryPlanArtifact;
use Quantum\Database\Operation\Sqg\SemanticQueryGraph;

/**
 * Operación SQG: AST tipificado + certificación fingerprint opcional.
 * V1: SemanticQueryGraph se define en F2; aquí placeholder forward compatible.
 */
final readonly class SqgOperation implements DatabaseOperationInterface
{
    public function __construct(
        public OperationKind $kind,
        /** @phpstan-ignore-next-line SQG is defined in Fase 2 */
        public /* SemanticQueryGraph */ mixed $graph,
        public ?string $certificationFingerprint = null,
        public ?QueryOptimizationResult $optimizationResult = null,
        public ?QueryPlanArtifact $planArtifact = null,
    ) {}

    public function kind(): OperationKind { return $this->kind; }

    /** @return SemanticQueryGraph */
    public function graph(): mixed { return $this->graph; }

    public function describe(): string
    {
        return sprintf('[%s] graph=%s cert=%s optimized=%s planned=%s',
            $this->kind->value,
            is_object($this->graph) ? $this->graph::class : 'null',
            $this->certificationFingerprint ?? 'none',
            $this->optimizationResult !== null ? 'yes' : 'no',
            $this->planArtifact !== null ? 'yes' : 'no',
        );
    }
}
