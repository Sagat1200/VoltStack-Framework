<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg;

/**
 * Tabla de símbolos con stack de scopes.
 */
final class SymbolTable
{
    /** @var array<string,Scope> */
    private array $scopesById = [];

    public function __construct(
        public readonly Scope $root = new Scope(id: 'root'),
    ) {
        $this->scopesById[$root->id] = $root;
    }

    public function enter(string $kind, ?string $alias = null, ?Scope $parent = null): Scope
    {
        $parent ??= $this->root;
        $id = 's_' . bin2hex(random_bytes(5));
        $s = new Scope(id: $id, parent: $parent, kind: $kind, alias: $alias);
        $this->scopesById[$id] = $s;
        return $s;
    }

    public function get(string $id): ?Scope { return $this->scopesById[$id] ?? null; }

    public function root(): Scope { return $this->root; }
}
