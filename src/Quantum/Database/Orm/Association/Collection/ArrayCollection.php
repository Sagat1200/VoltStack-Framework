<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Collection;

/**
 * Implementación simple de colección NO persistente (inicializada por el usuario en entity __construct).
 *
 * @template T of object
 * @implements CollectionInterface<T>
 */
final class ArrayCollection implements CollectionInterface
{
    /** @var list<T> */
    private array $elements;

    /** @param iterable<T> $elements */
    public function __construct(iterable $elements = [])
    {
        $this->elements = is_array($elements) ? array_values($elements) : iterator_to_array($elements, false);
    }

    public function add(object $element): bool
    {
        foreach ($this->elements as $e) {
            if ($e === $element) return false;
        }
        $this->elements[] = $element;
        return true;
    }

    public function remove(int|object $keyOrObject): ?object
    {
        if (is_int($keyOrObject)) {
            if (!isset($this->elements[$keyOrObject])) return null;
            $removed = $this->elements[$keyOrObject];
            array_splice($this->elements, $keyOrObject, 1);
            return $removed;
        }
        foreach ($this->elements as $i => $e) {
            if ($e === $keyOrObject) {
                array_splice($this->elements, $i, 1);
                return $e;
            }
        }
        return null;
    }

    public function contains(object $element): bool
    {
        foreach ($this->elements as $e) {
            if ($e === $element) return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    public function isEmpty(): bool
    {
        return count($this->elements) === 0;
    }

    public function toArray(): array
    {
        return $this->elements;
    }

    public function map(\Closure $fn): self
    {
        return new self(array_map($fn, $this->elements));
    }

    public function filter(\Closure $fn): self
    {
        return new self(array_values(array_filter($this->elements, $fn(...))));
    }

    public function first(): ?object
    {
        return $this->elements[0] ?? null;
    }

    public function last(): ?object
    {
        $n = count($this->elements);
        return $n === 0 ? null : $this->elements[$n - 1];
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->elements, $offset, $length));
    }

    public function count(): int
    {
        return count($this->elements);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->elements);
    }
}
