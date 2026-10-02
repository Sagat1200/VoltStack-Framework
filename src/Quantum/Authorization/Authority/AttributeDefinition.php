<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Metadata\MetadataValueType;

/**
 * Define un atributo ABAC que se espera en el contexto o en el subject.
 *
 * Sirve como contrato de schema para attributes dinámicos: nombre, tipo de dato
 * (boolean/string/integer/float/array/enum) y lista opcional de constraints
 * (enum values, expression regex, rango). En esta primera versión se usa como
 * DTO descriptivo y como forma de normalizar requirements antes de enviarlos
 * al ManifestStage.
 */
final readonly class AttributeDefinition
{
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'float';
    public const TYPE_ARRAY = 'array';
    public const TYPE_ENUM = 'enum';
    public const TYPE_ANY = 'any';

    /**
     * @param string $name
     * @param string $type
     * @param array<string, mixed> $constraints
     *   - `values`: list<string> cuando type=enum
     *   - `pattern`: string regex cuando type=string
     *   - `min` / `max`: integer|float para numeric types
     *   - `required`: bool (por defecto true)
     *   - `default`: valor por defecto
     * @param string|null $description
     */
    public function __construct(
        public string $name,
        public string $type = self::TYPE_ANY,
        public array $constraints = [],
        public ?string $description = null,
    ) {}

    public static function fromMetadataType(MetadataValueType $type, string $name): self
    {
        return match ($type) {
            MetadataValueType::Boolean => new self($name, self::TYPE_BOOLEAN),
            MetadataValueType::Integer => new self($name, self::TYPE_INTEGER),
            MetadataValueType::Float => new self($name, self::TYPE_FLOAT),
            MetadataValueType::String => new self($name, self::TYPE_STRING),
            MetadataValueType::List => new self($name, self::TYPE_ARRAY),
            default => new self($name, self::TYPE_ANY),
        };
    }

    /**
     * @return list<string>
     */
    public function enumValues(): array
    {
        if ($this->type !== self::TYPE_ENUM) {
            return [];
        }

        $values = $this->constraints['values'] ?? [];

        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    public function isRequired(): bool
    {
        if (! array_key_exists('required', $this->constraints)) {
            return true;
        }

        return (bool) $this->constraints['required'];
    }

    public function accepts(mixed $value): bool
    {
        if ($this->type === self::TYPE_ANY) {
            return true;
        }

        if ($value === null) {
            return ! $this->isRequired() || array_key_exists('default', $this->constraints);
        }

        return match ($this->type) {
            self::TYPE_BOOLEAN => is_bool($value),
            self::TYPE_INTEGER => is_int($value) && $this->withinNumericRange($value),
            self::TYPE_FLOAT => (is_int($value) || is_float($value)) && $this->withinNumericRange((float) $value),
            self::TYPE_STRING => is_string($value) && ($this->patternMatches($value)),
            self::TYPE_ARRAY => is_array($value),
            self::TYPE_ENUM => in_array((string) $value, $this->enumValues(), true),
            default => true,
        };
    }

    private function patternMatches(string $value): bool
    {
        $pattern = $this->constraints['pattern'] ?? null;

        if (! is_string($pattern) || $pattern === '') {
            return true;
        }

        return (bool) @preg_match($pattern, $value);
    }

    private function withinNumericRange(int|float $value): bool
    {
        $min = $this->constraints['min'] ?? null;
        if ((is_int($min) || is_float($min)) && $value < $min) {
            return false;
        }

        $max = $this->constraints['max'] ?? null;
        if ((is_int($max) || is_float($max)) && $value > $max) {
            return false;
        }

        return true;
    }

    /**
     * @return array{name:string,type:string,constraints:array<string, mixed>,description:string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'constraints' => $this->constraints,
            'description' => $this->description,
        ];
    }
}
