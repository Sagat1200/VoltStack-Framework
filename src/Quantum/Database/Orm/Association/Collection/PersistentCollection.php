<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Collection;

use Quantum\Database\Orm\Metadata\CompiledAssociationMetadata;

/**
 * PersistentCollection: colecciones OneToMany / ManyToMany hidratadas por el ORM.
 *
 * Invariantes:
 *   - Si `initialized = false` (LAZY), count/foreach/offsetGet dispara `$loader` closure para hidratar desde BD.
 *   - Dirty tracking mantiene `added` y `removed` (diff exacto entre snapshot y actual).
 *   - Serialización: solo se serializan elements + flag initialized=true (se pierde loader, que requiere EM, pero eso es esperado en V1).
 *
 * @template T of object
 * @implements CollectionInterface<T>
 * @implements \ArrayAccess<int,T>
 */
final class PersistentCollection implements CollectionInterface, \ArrayAccess
{
    /** @var list<T> */
    private array $elements = [];
    /** @var list<T> snapshot original al terminar initialize/hydrate o takeSnapshot() */
    private array $snapshot = [];
    /** @var array<int,T> added after initialize por oid de object */
    private array $added = [];
    /** @var array<int,T> removed after initialize por oid de object */
    private array $removed = [];

    private bool $initialized;

    /**
     * @param \Closure(): list<T>|null $loader
     */
    public function __construct(
        public readonly CompiledAssociationMetadata $assocMeta,
        private readonly ?object $em = null,
        private readonly ?\Closure $loader = null,
        array $initialElements = [],
    ) {
        if (count($initialElements) > 0) {
            $this->elements = array_values($initialElements);
            $this->initialized = true;
            $this->takeSnapshot();
        } else {
            $this->initialized = $loader === null;  // si no hay loader → initialized=true (vacío)
        }
    }

    // ---------- Init trigger ----------
    private function ensureInitialized(): void
    {
        if ($this->initialized) return;
        if ($this->loader === null) {
            $this->initialized = true;
            return;
        }
        $loader = $this->loader;
        $raw = $loader();
        $this->elements = is_array($raw) ? array_values($raw) : iterator_to_array($raw, false);
        $this->initialized = true;
        $this->takeSnapshot();
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    // ---------- Dirty tracking ----------
    public function takeSnapshot(): void
    {
        $this->snapshot = $this->elements;
        $this->added = [];
        $this->removed = [];
    }

    /** @return array<int,T> */
    public function getAdded(): array
    {
        return array_values($this->added);
    }

    /** @return array<int,T> */
    public function getRemoved(): array
    {
        return array_values($this->removed);
    }

    public function isDirty(): bool
    {
        return count($this->added) > 0 || count($this->removed) > 0;
    }

    /**
     * Diff para ORPHAN REMOVAL: items presentes en snapshot PERO NO en actual elements → marcados orphan.
     * @return list<T>
     */
    public function getDeleteDiff(): array
    {
        $this->ensureInitialized();
        $currentOids = [];
        foreach ($this->elements as $e) $currentOids[spl_object_id($e)] = true;
        $out = [];
        foreach ($this->snapshot as $old) {
            if (!isset($currentOids[spl_object_id($old)])) $out[] = $old;
        }
        return $out;
    }

    /**
     * Diff para INSERTAR: items actuales NO en snapshot → added.
     * @return list<T>
     */
    public function getInsertDiff(): array
    {
        $this->ensureInitialized();
        $snapOids = [];
        foreach ($this->snapshot as $e) $snapOids[spl_object_id($e)] = true;
        $out = [];
        foreach ($this->elements as $new) {
            if (!isset($snapOids[spl_object_id($new)])) $out[] = $new;
        }
        return $out;
    }

    // ---------- CollectionInterface ----------
    public function add(object $element): bool
    {
        $this->ensureInitialized();
        foreach ($this->elements as $e) {
            if ($e === $element) return false;
        }
        $this->elements[] = $element;
        $oid = spl_object_id($element);
        // Si estaba en removed (quitado y readded) → quitar de removed
        if (isset($this->removed[$oid])) unset($this->removed[$oid]);
        else $this->added[$oid] = $element;
        return true;
    }

    public function remove(int|object $keyOrObject): ?object
    {
        $this->ensureInitialized();
        $idx = null;
        $found = null;
        if (is_int($keyOrObject)) {
            if (!isset($this->elements[$keyOrObject])) return null;
            $idx = $keyOrObject;
            $found = $this->elements[$idx];
        } else {
            foreach ($this->elements as $i => $e) {
                if ($e === $keyOrObject) {
                    $idx = $i;
                    $found = $e;
                    break;
                }
            }
            if ($found === null) return null;
        }
        array_splice($this->elements, $idx, 1);
        $oid = spl_object_id($found);
        if (isset($this->added[$oid])) unset($this->added[$oid]);
        else $this->removed[$oid] = $found;
        return $found;
    }

    public function contains(object $element): bool
    {
        $this->ensureInitialized();
        foreach ($this->elements as $e) {
            if ($e === $element) return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->ensureInitialized();
        foreach ($this->elements as $e) {
            $oid = spl_object_id($e);
            if (isset($this->added[$oid])) unset($this->added[$oid]);
            else $this->removed[$oid] = $e;
        }
        $this->elements = [];
    }

    public function isEmpty(): bool
    {
        $this->ensureInitialized();
        return count($this->elements) === 0;
    }

    public function toArray(): array
    {
        $this->ensureInitialized();
        return $this->elements;
    }

    public function map(\Closure $fn): CollectionInterface
    {
        $this->ensureInitialized();
        return new ArrayCollection(array_map($fn, $this->elements));
    }

    public function filter(\Closure $fn): CollectionInterface
    {
        $this->ensureInitialized();
        return new ArrayCollection(array_values(array_filter($this->elements, $fn(...))));
    }

    public function first(): ?object
    {
        $this->ensureInitialized();
        return $this->elements[0] ?? null;
    }

    public function last(): ?object
    {
        $this->ensureInitialized();
        $n = count($this->elements);
        return $n === 0 ? null : $this->elements[$n - 1];
    }

    public function slice(int $offset, ?int $length = null): CollectionInterface
    {
        $this->ensureInitialized();
        return new ArrayCollection(array_slice($this->elements, $offset, $length));
    }

    // ---------- Countable / IteratorAggregate ----------
    public function count(): int
    {
        $this->ensureInitialized();
        return count($this->elements);
    }

    public function getIterator(): \Traversable
    {
        $this->ensureInitialized();
        return new \ArrayIterator($this->elements);
    }

    // ---------- ArrayAccess ----------
    public function offsetExists(mixed $offset): bool
    {
        $this->ensureInitialized();
        return is_int($offset) && isset($this->elements[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->ensureInitialized();
        return is_int($offset) ? ($this->elements[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->ensureInitialized();
        if (!is_object($value)) return;
        if ($offset === null) {
            $this->add($value);
            return;
        }
        if (!is_int($offset)) return;
        $old = $this->elements[$offset] ?? null;
        if ($old !== null && $old !== $value) {
            $oidOld = spl_object_id($old);
            if (isset($this->added[$oidOld])) unset($this->added[$oidOld]);
            else $this->removed[$oidOld] = $old;
        }
        $this->elements[$offset] = $value;
        $oidNew = spl_object_id($value);
        if (isset($this->removed[$oidNew])) unset($this->removed[$oidNew]);
        else $this->added[$oidNew] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_int($offset)) $this->remove($offset);
    }

    // ---------- Serialize safety ----------
    public function __serialize(): array
    {
        // Al serializar NO podemos guardar $loader (Closure no serializable).
        // Forzamos initialized=true y guardamos los elements. Esto es: cuando se
        // recupere de cache/sesión, trabajará con los elementos en memoria.
        if (!$this->initialized) $this->ensureInitialized();
        return [
            'assocMeta' => $this->assocMeta,
            'elements' => $this->elements,
            'snapshot' => $this->snapshot,
            'added' => $this->added,
            'removed' => $this->removed,
            'initialized' => true,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->assocMeta = $data['assocMeta']; // @phpstan-ignore-line readonly written here
        $this->elements = $data['elements'];
        $this->snapshot = $data['snapshot'];
        $this->added = $data['added'];
        $this->removed = $data['removed'];
        $this->initialized = true;
    }
}
