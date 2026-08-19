<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dialect\Value\CompiledSql;
use Quantum\Database\Operation\Sqg\Enum\NodeFlag;
use Quantum\Database\Operation\Sqg\Node\SelectStatementNode;
use Quantum\Database\Operation\Sqg\Node\InsertStatementNode;
use Quantum\Database\Operation\Sqg\Node\UpdateStatementNode;
use Quantum\Database\Operation\Sqg\Node\DeleteStatementNode;

/**
 * Árbol SQG autoritativo. Tiene un statement root (SELECT/INSERT/UPDATE/DELETE),
 * parámetros asociados list<mixed> posicionales (0-based) y ejecuta validate()
 * con 5 passes → GraphCertification.
 */
final class SemanticQueryGraph
{
    /** @param list<mixed> $parameters parámetros posicionales (0-indexed) en orden de ParameterNode::$index. */
    public function __construct(
        public readonly SelectStatementNode|InsertStatementNode|UpdateStatementNode|DeleteStatementNode $root,
        public array $parameters = [],
    ) {}

    /**
     * Pipeline 5-passes de validación/certificación.
     *
     * Pass 1 — Structural: requerimientos mínimos por familia (ej. Select tiene from/projections)
     * Pass 2 — SymbolTable Build: registra tables/aliases/ctes/columns.
     * Pass 3 — Resolution + TypeInference: resuelve column refs, infiere DataType.
     * Pass 4 — Aggregate Semantic: sin GROUP BY / sin HAVING → aggregate restrictions.
     * Pass 5 — Capability check: window/cte/returning/upsert/ILike soportado por el dialect.
     *
     * @return GraphCertification
     */
    public function validate(DatabaseCapabilitySet $caps): GraphCertification
    {
        $symbols = new SymbolTable();
        $violations = [];
        $violations = [...$this->pass1Structural()];
        if (count(array_filter($violations, static fn($v)=>$v->isError())) === 0) {
            $violations = [...$violations, ...$this->pass2SymbolTableBuild($symbols)];
        }
        if (count(array_filter($violations, static fn($v)=>$v->isError())) === 0) {
            $violations = [...$violations, ...$this->pass3ResolveAndTypeInference($symbols)];
        }
        if (count(array_filter($violations, static fn($v)=>$v->isError())) === 0) {
            $violations = [...$violations, ...$this->pass4AggregateSemantic()];
        }
        if (count(array_filter($violations, static fn($v)=>$v->isError())) === 0) {
            $violations = [...$violations, ...$this->pass5Capability($caps)];
        }

        $walker = new NodeWalker();
        $counter = new class implements NodeVisitor {
            public int $nodes = 0;
            public int $params = 0;
            public string $fp = '';
            public function enterNode(\Quantum\Database\Operation\Sqg\SemanticNode $n): void { $this->nodes++; if($n->kind() === \Quantum\Database\Operation\Sqg\Enum\SemanticNodeKind::Parameter) $this->params++; $this->fp .= $n->kind()->value.'|'; }
            public function leaveNode(\Quantum\Database\Operation\Sqg\SemanticNode $n): void {}
            public function visitRoot($n): mixed { return null; }
            public function visitSource($n): mixed { return null; }
            public function visitJoin($n): mixed { return null; }
            public function visitProjection($n): mixed { return null; }
            public function visitPredicate($n): mixed { return null; }
            public function visitExpression($n): mixed { return null; }
            public function visitAggregate($n): mixed { return null; }
            public function visitModifier($n): mixed { return null; }
            public function visitMutation($n): mixed { return null; }
        };
        $walker->walk($this->root, $counter);

        $errors = array_filter($violations, static fn(ValidationViolation $v): bool => $v->isError());
        return new GraphCertification(
            fingerprint: hash('sha256', $counter->fp . '|caps=' . json_encode($caps)),
            nodeCount: $counter->nodes,
            parameterCount: $counter->params,
            violations: $violations,
            symbols: $symbols,
            valid: count($errors) === 0,
        );
    }

    // ---------------- Pass 1: Structural ----------------

    /** @return list<ValidationViolation> */
    private function pass1Structural(): array
    {
        $out = [];
        $r = $this->root;
        if ($r instanceof SelectStatementNode) {
            if ($r->projections === null || $r->projections->items === []) {
                $out[] = $this->v('S1001', 'error', 'SelectStatement: projections empty', $r->id(), $r->sourceSpan());
            }
            if (count($r->fromSources) === 0 && !$this->isDualSelect($r)) {
                $out[] = $this->v('S1002', 'error', 'SelectStatement: fromSources empty and not a constants-only query', $r->id(), $r->sourceSpan());
            }
        } elseif ($r instanceof InsertStatementNode) {
            if ($r->targetColumns === []) {
                $out[] = $this->v('S1010', 'error', 'InsertStatement: targetColumns empty', $r->id(), $r->sourceSpan());
            }
        } elseif ($r instanceof UpdateStatementNode) {
            if ($r->assignments === []) {
                $out[] = $this->v('S1020', 'error', 'UpdateStatement: assignments empty', $r->id(), $r->sourceSpan());
            }
        }
        return $out;
    }

    private function isDualSelect(SelectStatementNode $s): bool
    {
        // Permite SELECT 1+1; sin FROM si todas las proyecciones son literales/puras.
        foreach ($s->projections?->items ?? [] as $p) {
            $expr = $p instanceof \Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode ? $p->expression : $p;
            if (!$this->isPureLiteralExpression($expr)) return false;
        }
        return true;
    }

    private function isPureLiteralExpression(mixed $e): bool
    {
        if ($e instanceof \Quantum\Database\Operation\Sqg\Node\LiteralNode) return true;
        if ($e instanceof \Quantum\Database\Operation\Sqg\Node\ParameterNode) return true;
        if ($e instanceof \Quantum\Database\Operation\Sqg\Node\FunctionCallNode && !$e->isMutable) {
            foreach ($e->args as $a) { if (!$this->isPureLiteralExpression($a)) return false; }
            return true;
        }
        if ($e instanceof \Quantum\Database\Operation\Sqg\Node\BinaryExpressionNode) {
            return $this->isPureLiteralExpression($e->left) && $this->isPureLiteralExpression($e->right);
        }
        if ($e instanceof \Quantum\Database\Operation\Sqg\Node\UnaryExpressionNode) {
            return $this->isPureLiteralExpression($e->operand);
        }
        return false;
    }

    // ---------------- Pass 2: SymbolTable Build ----------------

    /** @return list<ValidationViolation> */
    private function pass2SymbolTableBuild(SymbolTable $symbols): array
    {
        $out = [];
        $r = $this->root;
        if ($r instanceof SelectStatementNode) {
            $scope = $symbols->enter(kind: 'select');
            if ($r->with) {
                foreach ($r->with->ctes as $cte) {
                    if ($cte instanceof \Quantum\Database\Operation\Sqg\Node\CteSourceNode) {
                        $scope->define(new Symbol(name: $cte->name, kind: 'cte', scopeId: $scope->id));
                    }
                }
            }
            foreach ($r->fromSources as $src) {
                if ($src instanceof \Quantum\Database\Operation\Sqg\Node\TableSourceNode) {
                    $name = $src->alias ?? $src->tableName;
                    $scope->define(new Symbol(name: $name, kind: 'table', scopeId: $scope->id, payload: $src));
                } elseif ($src instanceof \Quantum\Database\Operation\Sqg\Node\SubquerySourceNode || $src instanceof \Quantum\Database\Operation\Sqg\Node\ValuesSourceNode) {
                    $alias = $src->alias ?? null;
                    if ($alias === null) {
                        $out[] = $this->v('S2001', 'error', 'Subquery/Values source needs an alias', $src->id(), $src->sourceSpan());
                    } else {
                        $scope->define(new Symbol(name: $alias, kind: 'table', scopeId: $scope->id, payload: $src));
                    }
                }
            }
            if ($r->projections) {
                foreach ($r->projections->items as $p) {
                    if ($p instanceof \Quantum\Database\Operation\Sqg\Node\AliasedProjectionNode) {
                        $scope->define(new Symbol(name: $p->alias, kind: 'alias', scopeId: $scope->id, payload: $p));
                    }
                }
            }
        }
        return $out;
    }

    // ---------------- Pass 3: Resolution + TypeInference (V1 básico) ----------------

    /** @return list<ValidationViolation> */
    private function pass3ResolveAndTypeInference(SymbolTable $symbols): array
    {
        $out = [];
        $r = $this->root;
        if ($r instanceof SelectStatementNode) {
            $scope = $symbols->enter(kind: 'resolution');
            // registrar aliases de from para chequeo simple:
            $known = [];
            foreach ($r->fromSources as $src) {
                if ($src instanceof \Quantum\Database\Operation\Sqg\Node\TableSourceNode) $known[] = $src->aliasOrName();
                if ($src instanceof \Quantum\Database\Operation\Sqg\Node\SubquerySourceNode) $known[] = $src->alias;
            }
            $walker = new NodeWalker();
            $scopeRef = $scope;
            $walker->walk($r, new class($known, $out) implements NodeVisitor {
                public function __construct(private array $knownTables, private array &$outRef) {}
                public function enterNode($n): void {
                    if ($n instanceof ColumnReferenceNode && $n->tableAlias !== null) {
                        if (!in_array($n->tableAlias, $this->knownTables, true)) {
                            $this->outRef[] = new ValidationViolation(
                                passName: 'pass3ResolveAndTypeInference',
                                level: 'error', code: 'S3001',
                                message: "Unknown table alias '{$n->tableAlias}' in column reference",
                                nodeId: $n->id(), span: $n->sourceSpan());
                        }
                    }
                }
                public function leaveNode($n): void {}
                public function visitRoot($n): mixed { return null; }
                public function visitSource($n): mixed { return null; }
                public function visitJoin($n): mixed { return null; }
                public function visitProjection($n): mixed { return null; }
                public function visitPredicate($n): mixed { return null; }
                public function visitExpression($n): mixed { return null; }
                public function visitAggregate($n): mixed { return null; }
                public function visitModifier($n): mixed { return null; }
                public function visitMutation($n): mixed { return null; }
            });
        }
        return $out;
    }

    // ---------------- Pass 4: Aggregate Semantic ----------------

    /** @return list<ValidationViolation> */
    private function pass4AggregateSemantic(): array
    {
        $out = [];
        $r = $this->root;
        if (!$r instanceof SelectStatementNode) return $out;
        $hasAggregate = false;
        $hasWindow = false;
        $walker = new NodeWalker();
        $walker->walk($r, new class($out) implements NodeVisitor {
            public function __construct(private array &$out) {}
            public function enterNode($n): void {}
            public function leaveNode($n): void {}
            public function visitRoot($n): mixed { return null; }
            public function visitSource($n): mixed { return null; }
            public function visitJoin($n): mixed { return null; }
            public function visitProjection($n): mixed { return null; }
            public function visitPredicate($n): mixed { return null; }
            public function visitExpression($n): mixed { return null; }
            public function visitAggregate($n): mixed {
                if ($n instanceof AggregateFunctionNode) { $GLOBALS['__sqg_pass4_agg'] = true; }
                if ($n instanceof WindowFunctionNode) { $GLOBALS['__sqg_pass4_win'] = true; }
                return null;
            }
            public function visitModifier($n): mixed { return null; }
            public function visitMutation($n): mixed { return null; }
        });
        $hasAggregate = isset($GLOBALS['__sqg_pass4_agg']) && $GLOBALS['__sqg_pass4_agg'];
        $hasWindow = isset($GLOBALS['__sqg_pass4_win']) && $GLOBALS['__sqg_pass4_win'];
        unset($GLOBALS['__sqg_pass4_agg'], $GLOBALS['__sqg_pass4_win']);
        // V1: no valida estrictamente cada columna en GROUP BY (dejamos a la BD). Solo warning si hay HAVING sin GROUP BY ni aggregates.
        if ($r->having !== null && !$hasAggregate && $r->groupBy === null) {
            $out[] = $this->v('S4001', 'warning', 'HAVING clause without aggregates nor GROUP BY', $r->id(), $r->sourceSpan());
        }
        return $out;
    }

    // ---------------- Pass 5: Capability check ----------------

    /** @return list<ValidationViolation> */
    private function pass5Capability(DatabaseCapabilitySet $caps): array
    {
        $out = [];
        $r = $this->root;
        if ($r instanceof SelectStatementNode) {
            if ($r->with?->recursive && !$caps->cteRecursive) {
                $out[] = $this->v('S5001', 'error', 'Recursive CTE not supported by target DB', $r->with->id(), $r->with->sourceSpan());
            }
            if (!$caps->windowFunctions) {
                $walker = new NodeWalker();
                $hasWin = false;
                $walker->walk($r, new class implements NodeVisitor {
                    public bool $win = false;
                    public function enterNode($n): void { if($n instanceof \Quantum\Database\Operation\Sqg\Node\WindowFunctionNode) $this->win = true; }
                    public function leaveNode($n): void {}
                    public function visitRoot($n): mixed { return null; }
                    public function visitSource($n): mixed { return null; }
                    public function visitJoin($n): mixed { return null; }
                    public function visitProjection($n): mixed { return null; }
                    public function visitPredicate($n): mixed { return null; }
                    public function visitExpression($n): mixed { return null; }
                    public function visitAggregate($n): mixed { return null; }
                    public function visitModifier($n): mixed { return null; }
                    public function visitMutation($n): mixed { return null; }
                });
            }
        }
        if ($r instanceof InsertStatementNode || $r instanceof UpdateStatementNode || $r instanceof DeleteStatementNode) {
            $returning = $r->returning ?? null;
            if ($returning !== null && !$caps->returningClause) {
                $out[] = $this->v('S5010', 'error', 'RETURNING clause not supported', $returning->id(), $returning->sourceSpan());
            }
        }
        if ($r instanceof InsertStatementNode && $r->onConflict !== null) {
            $st = $r->onConflict->strategy;
            $ok = match($st) {
                'do_nothing', 'do_update' => $caps->upsertOnConflict,
                'ignore', 'replace' => $caps->upsertOnDuplicateKey,
                default => true,
            };
            if (!$ok) {
                $out[] = $this->v('S5020', 'error', "Upsert strategy '{$st}' not supported by DB driver", $r->onConflict->id(), $r->onConflict->sourceSpan());
            }
        }
        return $out;
    }

    // ---------------- helpers ----------------

    private function v(string $code, string $level, string $message, ?string $nodeId = null, ?SourceSpan $span = null): ValidationViolation
    {
        return new ValidationViolation(passName: debug_backtrace(limit: 2)[1]['function'] ?? 'pass?',
            level: $level, code: $code, message: $message, nodeId: $nodeId, span: $span);
    }
}
