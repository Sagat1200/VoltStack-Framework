<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Ámbito léxico para el symbol table builder. Cada SELECT / SUBQUERY introduce un scope.
 */
final class Scope
{
    /** @var array<string,Symbol> */
    private array $symbols = [];

    public function __construct(
        public readonly string $id,
        public readonly ?Scope $parent = null,
        public readonly string $kind = 'select',   // 'select' | 'cte' | 'subquery' | 'values'
        public readonly ?string $alias = null,
    ) {}

    public function define(Symbol $s): void
    {
        $this->symbols[$s->name] = $s;
    }

    public function resolve(string $name, bool $localOnly = false): ?Symbol
    {
        if (isset($this->symbols[$name])) return $this->symbols[$name];
        if (!$localOnly && $this->parent !== null) {
            return $this->parent->resolve($name);
        }
        return null;
    }

    /**
     * Resolver nombre de columna `a.b` o simple `b`.
     * Devuelve list<Symbol> todos los matches.
     *
     * @return list<Symbol>
     */
    public function resolveColumn(?string $tableAlias, string $column): array
    {
        $out = [];
        $visited = [];
        $scope = $this;
        while ($scope !== null && !in_array($scope->id, $visited, true)) {
            $visited[] = $scope->id;
            foreach ($scope->symbols as $s) {
                if ($s->kind !== 'column') continue;
                if ($tableAlias !== null && $s->tableAlias !== $tableAlias) continue;
                $name = $s->physicalColumn ?? $s->name;
                if ($name === $column) $out[] = $s;
            }
            $scope = $scope->parent;
        }
        return $out;
    }

    /** @return array<string,Symbol> */
    public function localSymbols(): array { return $this->symbols; }
}
