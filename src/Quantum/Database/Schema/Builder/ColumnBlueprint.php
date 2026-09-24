<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Builder;

use Quantum\Database\Schema\Model\ColumnDefinition;

final class ColumnBlueprint
{
    private bool $nullable = false;
    private bool $primary = false;
    private bool $autoIncrement = false;
    private bool $unique = false;
    private mixed $default = null;
    private bool $hasDefault = false;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
    ) {
    }

    public function nullable(bool $enabled = true): self
    {
        $this->nullable = $enabled;

        return $this;
    }

    public function primary(bool $enabled = true): self
    {
        $this->primary = $enabled;

        return $this;
    }

    public function autoIncrement(bool $enabled = true): self
    {
        $this->autoIncrement = $enabled;

        return $this;
    }

    public function unique(bool $enabled = true): self
    {
        $this->unique = $enabled;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function toDefinition(): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $this->name,
            type: $this->type,
            nullable: $this->nullable,
            primary: $this->primary,
            autoIncrement: $this->autoIncrement,
            unique: $this->unique,
            default: $this->default,
            hasDefault: $this->hasDefault,
        );
    }
}
