<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

/**
 * Window function (V1 base).
 */
final class WindowFunctionNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly string $functionName,
        public readonly array $args,
        public readonly ?WindowSpecificationNode $window = null,
        public readonly ?string $windowRef = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) {
        $this->initNode($id, $flags | \Quantum\Database\Operation\Sqg\Enum\NodeFlag::WindowPresent->value, $span);
    }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::WindowFunction; }
    public function children(): array
    {
        $out = array_values($this->args);
        if ($this->window) $out[] = $this->window;
        return $out;
    }
    public function accept(NodeVisitor $v): mixed { return $v->visitAggregate($this); }
}
