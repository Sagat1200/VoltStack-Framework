<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata\Attribute;

/**
 * #[TenantColumn]: marca columna multi-tenant. Debe estar asociada a una
 * asociación ManyToOne a Tenant entity.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class TenantColumn
{
    public function __construct(public string $column = 'tenant_id') {}
}
