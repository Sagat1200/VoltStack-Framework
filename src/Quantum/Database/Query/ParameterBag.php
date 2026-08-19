<?php declare(strict_types=1);

namespace Quantum\Database\Query;

use Quantum\Database\Dbal\Enum\ParamType;

/**
 * Internal ParameterBag: named parameters with optional explicit ParamType.
 * Builds positional parameter list in deterministic order when requested.
 *
 * Usage inside SelectQueryBuilder state only; NOT a public API contract class.
 *
 * @internal
 */
final class ParameterBag
{
    /**
     * Named parameters storage.
     *
     * @var array<string,array{value:mixed,type:?ParamType}>
     */
    private array $items = [];

    /**
     * Set a single named parameter (overwrites if exists).
     */
    public function set(string $name, mixed $value, ?ParamType $type = null): void
    {
        $this->items[$name] = ['value' => $value, 'type' => $type];
    }

    /**
     * Merge another bag or key-value array into this bag.
     *
     * @param self|array<string,mixed> $parameters
     */
    public function merge(self|array $parameters): void
    {
        if ($parameters instanceof self) {
            foreach ($parameters->items as $n => $v) {
                $this->items[$n] = $v;
            }
            return;
        }
        foreach ($parameters as $n => $v) {
            if (is_array($v) && array_key_exists('value', $v)) {
                $this->items[$n] = [
                    'value' => $v['value'],
                    'type'  => $v['type'] ?? null,
                ];
            } else {
                $this->items[$n] = ['value' => $v, 'type' => null];
            }
        }
    }

    /**
     * @return array<string,array{value:mixed,type:?ParamType}>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return parameter names in insertion order (for positional determinism).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->items);
    }

    /**
     * Return a list<value> ordered by names(). This is the positional list used
     * as the parameters binding for positional-style dialects (?, $1..$n).
     *
     * Note: when a positional SQG is built, parameters are emitted in the same
     * order as their FIRST OCCURRENCE in the SQL expression string. This is
     * computed by the SQG translator and overrides this raw-order output.
     *
     * @return list<mixed>
     */
    public function rawValues(): array
    {
        return array_values(array_map(fn(array $i): mixed => $i['value'], $this->items));
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->items);
    }

    public function get(string $name): mixed
    {
        return $this->items[$name]['value'] ?? null;
    }

    /**
     * Clone helper when SelectQueryBuilder clones itself (deep clone).
     */
    public function __clone()
    {
        // items are scalar/value objects; no deep clone needed.
    }
}
