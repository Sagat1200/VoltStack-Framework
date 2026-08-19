<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * CASE WHEN pred THEN expr [WHEN ...] [ELSE expr] END
 */
final class CaseExpressionNode implements SemanticNode
{
    use SemanticNodeTrait;

    /**
     * @param list<array{0:SemanticNode,1:SemanticNode}> $whenPairs when-then
     */
    public function __construct(
        public readonly array $whenPairs,
        public readonly ?SemanticNode $else = null,
        public readonly ?SemanticNode $operand = null, // CASE x WHEN ... END
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::CaseExpression; }
    public function children(): array
    {
        $out = [];
        if ($this->operand) $out[] = $this->operand;
        foreach ($this->whenPairs as [$w, $t]) {
            $out[] = $w;
            $out[] = $t;
        }
        if ($this->else) $out[] = $this->else;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitExpression($this); }
}
