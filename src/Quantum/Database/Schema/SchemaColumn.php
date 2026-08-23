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
        public ?string $portableType = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
    ) {}

    /**
     * @return array{name:string,type:string,portable_type:?string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int,length:?int,precision:?int,scale:?int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->nativeType,
            'portable_type' => $this->portableType,
            'nullable' => $this->nullable,
            'default' => $this->defaultValue,
            'primary' => $this->primaryKey,
            'auto_increment' => $this->autoIncrement,
            'ordinal' => $this->ordinal,
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
        ];
    }
}
