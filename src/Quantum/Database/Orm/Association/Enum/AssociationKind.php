<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Enum;

/**
 * Cardinalidad de una asociación ORM (CompiledAssociationMetadata).
 */
enum AssociationKind: string
{
    case OneToOne  = '1:1';
    case OneToMany = '1:N';
    case ManyToOne = 'N:1';
    case ManyToMany = 'N:M';
}