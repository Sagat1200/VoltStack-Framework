<?php

declare(strict_types=1);

namespace Quantum\Authorization\ABAC;

use Quantum\Authorization\Authority\AttributeDefinition;

final readonly class Condition
{
    /**
     * @param array<string, mixed> $constraints
     */
    private function __construct(
        public string $attribute,
        public string $type = AttributeDefinition::TYPE_ANY,
        public array $constraints = [],
        public ?string $description = null,
    ) {}

    /**
     * @param array<string, mixed> $constraints
     */
    public static function attribute(
        string $attribute,
        string $type = AttributeDefinition::TYPE_ANY,
        array $constraints = [],
        ?string $description = null,
    ): self {
        return new self(
            attribute: trim($attribute),
            type: trim($type) !== '' ? trim($type) : AttributeDefinition::TYPE_ANY,
            constraints: $constraints,
            description: $description,
        );
    }

    public static function max(string $attribute, int|float $max, ?string $description = null): self
    {
        return self::attribute($attribute, AttributeDefinition::TYPE_INTEGER, ['max' => $max], $description);
    }

    public static function min(string $attribute, int|float $min, ?string $description = null): self
    {
        return self::attribute($attribute, AttributeDefinition::TYPE_INTEGER, ['min' => $min], $description);
    }

    public static function between(string $attribute, int|float $min, int|float $max, ?string $description = null): self
    {
        return self::attribute($attribute, AttributeDefinition::TYPE_INTEGER, [
            'min' => $min,
            'max' => $max,
        ], $description);
    }

    /**
     * @param list<string> $values
     */
    public static function enum(string $attribute, array $values, ?string $description = null): self
    {
        return self::attribute($attribute, AttributeDefinition::TYPE_ENUM, ['values' => $values], $description);
    }

    public static function pattern(string $attribute, string $pattern, ?string $description = null): self
    {
        return self::attribute($attribute, AttributeDefinition::TYPE_STRING, ['pattern' => $pattern], $description);
    }

    public static function optional(
        string $attribute,
        string $type = AttributeDefinition::TYPE_ANY,
        array $constraints = [],
        ?string $description = null,
    ): self {
        return self::attribute($attribute, $type, [
            ...$constraints,
            'required' => false,
        ], $description);
    }

    /**
     * @return array{attribute:string,type:string,constraints:array<string,mixed>,description:string|null}
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute,
            'type' => $this->type,
            'constraints' => $this->constraints,
            'description' => $this->description,
        ];
    }
}
