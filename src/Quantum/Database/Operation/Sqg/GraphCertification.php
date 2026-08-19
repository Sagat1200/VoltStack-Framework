<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Operation\Sqg\Enum\NodeFlag;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\UpdateStatementNode;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;
use Quantum\Database\Operation\Sqg\Node\AggregateFunctionNode;
use Quantum\Database\Operation\Sqg\Node\WindowFunctionNode;
use Quantum\Database\Operation\Sqg\Node\ColumnReferenceNode;
use Quantum\Database\Operation\Sqg\Node\ProjectionListNode;
use Quantum\Database\Operation\Sqg\Node\GroupByListNode;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;

/**
 * Resultado del pipeline validate() 5-passes (DDD-V1-03 §7).
 */
final readonly class GraphCertification
{
    public function __construct(
        public string $fingerprint,
        public int $nodeCount,
        public int $parameterCount,
        public array $violations,
        public SymbolTable $symbols,
        public bool $valid,
    ) {}

    /** @return list<ValidationViolation> */
    public function errors(): array
    {
        return array_values(array_filter($this->violations, static fn(ValidationViolation $v): bool => $v->isError()));
    }
}
