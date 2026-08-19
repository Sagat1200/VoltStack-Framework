<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class FunctionCallNode implements SemanticNode
{
    use SemanticNodeTrait;

    /** @param list<SemanticNode> $args */
    public function __construct(
        public readonly string $functionName,
        public readonly array $args,
        public readonly bool $isMutable = false,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id,
            $flags | ($isMutable ? \Quantum\Database\Operation\Sqg\Enum\NodeFlag::HasMutableFunction->value : 0),
            $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::FunctionCall; }
    public function children(): array { return array_values($this->args); }
    public function accept(NodeVisitor $v): mixed { return $v->visitExpression($this); }
}
