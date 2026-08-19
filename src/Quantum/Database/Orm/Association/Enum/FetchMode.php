<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Enum;

/**
 * Modo de fetch de una asociación.
 *   Lazy     → proxy on-demand (default)
 *   Eager    → JOIN + load inmediato
 *   ExtraLazy → count/contains sin cargar la collection entera
 */
enum FetchMode: string
{
    case Lazy      = 'LAZY';
    case Eager     = 'EAGER';
    case ExtraLazy = 'EXTRA_LAZY';
}
