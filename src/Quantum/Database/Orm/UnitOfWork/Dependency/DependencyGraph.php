<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Dependency;

use Quantum\Database\Orm\UnitOfWork\Exception\OrmException;

/**
 * DAG de dependencias entre entidades. Arco from→to = "from depende de to (to debe ejecutarse antes)".
 *
 * Ejemplo: Post tiene FK a User → edge('Post#123', 'User#456').
 *
 * topologicalSort(): ejecuta Kahn algorithm → orden: [User#456, Post#123, ...]
 *
 * Golden UOW-002: ciclos → OrmException(code:CIRCULAR_DEPENDENCY_ORM_2201).
 */
final class DependencyGraph
{
    /**
     * Nodos conocidos.
     *
     * @var array<string,true>
     */
    private array $nodes = [];

    /**
     * Arcos: fromNode => [toNode => true].
     *
     * @var array<string,array<string,true>>
     */
    private array $outgoing = [];

    /**
     * Arcos inversos: toNode => [fromNode => true] (in-degree counter).
     *
     * @var array<string,array<string,true>>
     */
    private array $incoming = [];

    public function addNode(string $node): void
    {
        $this->nodes[$node] = true;
        if (!isset($this->outgoing[$node])) {
            $this->outgoing[$node] = [];
        }
        if (!isset($this->incoming[$node])) {
            $this->incoming[$node] = [];
        }
    }

    /**
     * @param string $from nodo origen (nodo dependiente).
     * @param string $to nodo destino (requiere que exista before).
     */
    public function addEdge(string $from, string $to): void
    {
        if ($from === $to) {
            throw new OrmException(
                "CIRCULAR_DEPENDENCY: self-loop edge {$from} → {$to} no permitido",
                'CIRCULAR_DEPENDENCY_ORM_2201',
            );
        }
        $this->addNode($from);
        $this->addNode($to);
        $this->outgoing[$from][$to] = true;
        $this->incoming[$to][$from] = true;
    }

    public function hasNode(string $node): bool
    {
        return isset($this->nodes[$node]);
    }

    /**
     * Topological sort.
     *
     * @return list<string>
     */
    public function topologicalSort(): array
    {
        // Kahn's: calcular in-degree usando $incoming.
        $inDegree = [];
        $queue = new \SplQueue();
        foreach (array_keys($this->nodes) as $n) {
            $d = count($this->incoming[$n] ?? []);
            $inDegree[$n] = $d;
            if ($d === 0) {
                $queue->enqueue($n);
            }
        }
        $visited = 0;
        $result = [];
        while (!$queue->isEmpty()) {
            $cur = $queue->dequeue();
            $result[] = $cur;
            $visited++;
            foreach (array_keys($this->outgoing[$cur] ?? []) as $neigh) {
                $inDegree[$neigh]--;
                if ($inDegree[$neigh] === 0) {
                    $queue->enqueue($neigh);
                }
            }
        }
        if ($visited !== count($this->nodes)) {
            $remaining = [];
            foreach ($inDegree as $n => $d) {
                if ($d > 0) {
                    $remaining[] = $n;
                }
            }
            throw new OrmException(
                "Ciclo detectado en DependencyGraph; nodos pendientes: " . implode(',', $remaining),
                'CIRCULAR_DEPENDENCY_ORM_2201',
            );
        }
        return $result;
    }

    /**
     * Reverse topological (para DELETEs: primero nodos hojas primero, root último).
     *
     * @return list<string>
     */
    public function topologicalSortReverse(): array
    {
        return array_reverse($this->topologicalSort());
    }

    public function clear(): void
    {
        $this->nodes = [];
        $this->outgoing = [];
        $this->incoming = [];
    }
}
