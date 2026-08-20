<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * Custom type bridge: bridge entre tipo de dominio PHP (ValueObject) ↔ columna DB.
 *
 * @template TPhp of object
 */
interface CustomTypeBridgeInterface
{
    /**
     * @return class-string<TPhp>
     */
    public function phpClass(): string;

    /**
     * @param mixed $rawDbValue valor desde DB (bindable por PDO).
     * @return TPhp|null
     */
    public function toPhpValue(mixed $rawDbValue, CompiledPropertyMetadata $ctx): mixed;

    /**
     * @param TPhp|mixed $phpDomainValue
     * @return mixed bindable via PDO (string/int/float/bool/null).
     */
    public function toDbValue(mixed $phpDomainValue, CompiledPropertyMetadata $ctx): mixed;

    public function underlyingDbType(): DataType;
}
