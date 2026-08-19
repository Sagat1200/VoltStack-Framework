<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * Parámetro posicional 0-based (el dialect lo traduce a ? / $N / :pN).
 */
final class ParameterNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly int $index,
        public readonly ?string $name = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id, $flags | \Quantum\Database\Operation\Sqg\Enum\NodeFlag::HasParameter->value, $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::Parameter; }
    public function children(): array { return []; }
    public function accept(NodeVisitor $v): mixed { return $v->visitExpression($this); }
}
