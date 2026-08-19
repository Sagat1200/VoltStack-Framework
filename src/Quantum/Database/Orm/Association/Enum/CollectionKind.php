<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Enum;

/**
 * Tipo de colección interna para OneToMany/ManyToMany.
 * V1: 'array' (simple array en memoria) + 'set' (no duplicates)
 */
enum CollectionKind: string
{
    case ArrayCollection    = 'array';
    case PersistentCollection = 'persistent';
    case Set                = 'set';
    case OrderedSet         = 'ordered_set';
}
