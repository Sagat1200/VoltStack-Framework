<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * Fuente de filas explícita: VALUES (?,?),(?,?)... (batch inserts)
 */
final class ValuesSourceNode implements SemanticNode
{
    use SemanticNodeTrait;

    /**
     * @param int $columnCount
     * @param int $rowCount
     * @param list<ParameterNode|LiteralNode|SemanticNode> $flattened row-major order
     */
    public function __construct(
        public readonly int $columnCount,
        public readonly int $rowCount,
        public readonly array $flattened,
        public readonly ?string $alias = null,
        public readonly ?array $columnAliases = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::ValuesSource; }
    public function children(): array { return array_values($this->flattened); }
    public function accept(NodeVisitor $v): mixed { return $v->visitSource($this); }
}
