<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Sqg\Enum;

/**
 * Flags por nodo (bitmask). Útiles para validación passes.
 */
enum NodeFlag: int
{
    case AggregatePresent   = 1 << 0;
    case WindowPresent      = 1 << 1;
    case HasParameter       = 1 << 2;
    case HasSubquery        = 1 << 3;
    case HasMutableFunction = 1 << 4;  // now(), random(), etc
    case DependentOnOuter   = 1 << 5;  // correlated (LATERAL/EXISTS cor)
    case IsDeterministic    = 1 << 6;
    case ResolvedType       = 1 << 7;  // Type inference pass marcó como listo
    case SymbolBound        = 1 << 8;  // Symbol table pass vinculó refs
}
