<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaColumn
{
    public function __construct(
        public string $name,
        public string $nativeType,
        public bool $nullable,
        public mixed $defaultValue = null,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public int $ordinal = 0,
    ) {}

    /**
     * @return array{name:string,type:string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->nativeType,
            'nullable' => $this->nullable,
            'default' => $this->defaultValue,
            'primary' => $this->primaryKey,
            'auto_increment' => $this->autoIncrement,
            'ordinal' => $this->ordinal,
        ];
    }
}