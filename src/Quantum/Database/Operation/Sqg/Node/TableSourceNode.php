<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Node;

use Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind;
use Quantum\Database\Operation\Sqg\NodeVisitor;
use Quantum\Database\Operation\Sqg\SemanticNode;

final class TableSourceNode implements SemanticNode
{
    use SemanticNodeTrait;

    public function __construct(
        public readonly string $tableName,
        public readonly ?string $alias = null,
        public readonly ?string $schema = null,
        ?string $id = null, int $flags = 0, ?\Quantum\Database\Operation\Sqg\SourceSpan $span = null,
    ) { $this->initNode($id, $flags, $span); }

    public function kind(): SemanticNodeKind { return SemanticNodeKind::TableSource; }
    public function children(): array { return []; }
    public function accept(NodeVisitor $v): mixed { return $v->visitSource($this); }

    public function aliasOrName(): string { return $this->alias ?? $this->tableName; }
}
