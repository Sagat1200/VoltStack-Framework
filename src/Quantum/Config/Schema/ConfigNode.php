<?php

declare(strict_types=1);

namespace Quantum\Config\Schema;

use InvalidArgumentException;
use Quantum\Config\MissingValue;

final readonly class ConfigNode
{
    /**
     * @param array<string, self> $children
     * @param list<string|int|bool> $allowedValues
     */
    private function __construct(
        private string $type,
        private bool $required = false,
        private bool $nullable = false,
        private mixed $default = MissingValue::Token,
        private array $children = [],
        private ?self $valueNode = null,
        private ?self $itemNode = null,
        private array $allowedValues = [],
        private bool $allowUnknown = false,
    ) {
    }

    public static function string(
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('string', $required, $nullable, $default);
    }

    public static function integer(
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('integer', $required, $nullable, $default);
    }

    public static function number(
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('number', $required, $nullable, $default);
    }

    public static function boolean(
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('boolean', $required, $nullable, $default);
    }

    /**
     * @param list<string|int|bool> $allowedValues
     */
    public static function enum(
        array $allowedValues,
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        if ($allowedValues === []) {
            throw new InvalidArgumentException('Enum node requires at least one allowed value.');
        }

        return new self('enum', $required, $nullable, $default, allowedValues: array_values($allowedValues));
    }

    /**
     * @param array<string, self> $children
     */
    public static function map(
        array $children,
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
        bool $allowUnknown = false,
    ): self {
        return new self('map', $required, $nullable, $default, children: $children, allowUnknown: $allowUnknown);
    }

    public static function dictionary(
        self $valueNode,
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('dictionary', $required, $nullable, $default, valueNode: $valueNode);
    }

    public static function listOf(
        self $itemNode,
        bool $required = false,
        bool $nullable = false,
        mixed $default = MissingValue::Token,
    ): self {
        return new self('list', $required, $nullable, $default, itemNode: $itemNode);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function nullable(): bool
    {
        return $this->nullable;
    }

    public function default(): mixed
    {
        return $this->default;
    }

    /**
     * @return array<string, self>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function valueNode(): ?self
    {
        return $this->valueNode;
    }

    public function itemNode(): ?self
    {
        return $this->itemNode;
    }

    /**
     * @return list<string|int|bool>
     */
    public function allowedValues(): array
    {
        return $this->allowedValues;
    }

    public function allowUnknown(): bool
    {
        return $this->allowUnknown;
    }

    public function hasDefault(): bool
    {
        return $this->default !== MissingValue::Token;
    }
}
