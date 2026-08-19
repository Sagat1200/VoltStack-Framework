<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Type;

use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * Representación de un tipo de dato: DataType + parámetros (length/precision/scale) y nullable.
 */
final readonly class TypeScheme
{
    public function __construct(
        public DataType $type,
        public bool $nullable = true,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?string $enumSetValues = null,
        public mixed $defaultRaw = null,
    ) {}

    public static function of(DataType $t, bool $nullable = true): self { return new self($t, $nullable); }

    public function withNullable(bool $v): self { return new self($this->type, $v, $this->length, $this->precision, $this->scale, $this->enumSetValues, $this->defaultRaw); }
}
