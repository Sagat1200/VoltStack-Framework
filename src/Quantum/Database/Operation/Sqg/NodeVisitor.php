<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Visitor pattern (doble dispatch) sobre SemanticNode.
 * NodeSqlEmitter / SymbolTableBuild / TypeInference / GraphValidator implements éste.
 *
 * V1 define métodos por FAMILIA de nodos (cubre 50 kinds concretos) para evitar inflar la interfaz.
 * Cada familia corresponde a categorías de SemanticNodeKind:
 *
 *   visitRoot | visitSource | visitJoin | visitProjection | visitPredicate
 *   visitExpression | visitAggregate | visitModifier | visitMutation
 */
interface NodeVisitor
{
    public function enterNode(SemanticNode $node): void;
    public function leaveNode(SemanticNode $node): void;

    public function visitRoot(SemanticNode $node): mixed;
    public function visitSource(SemanticNode $node): mixed;
    public function visitJoin(SemanticNode $node): mixed;
    public function visitProjection(SemanticNode $node): mixed;
    public function visitPredicate(SemanticNode $node): mixed;
    public function visitExpression(SemanticNode $node): mixed;
    public function visitAggregate(SemanticNode $node): mixed;
    public function visitModifier(SemanticNode $node): mixed;
    public function visitMutation(SemanticNode $node): mixed;
}
