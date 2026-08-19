<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[SoftDelete]: la propiedad deleted-at activa soft-delete.
 * La columna es 'deleted_at' por defecto (tipo Timestamp nullable).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class SoftDelete
{
    public function __construct(public string $column = 'deleted_at') {}
}
