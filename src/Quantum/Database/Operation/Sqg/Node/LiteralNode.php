<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * Literal con tipo (string/int/float/bool/null).
 */
final class LiteralNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly mixed $value,
        public readonly DataType $declaredType,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id, $flags, $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::Literal; }
    public function children(): array { return []; }
    public function accept(NodeVisitor $v): mixed { return $v->visitExpression($this); }
}
