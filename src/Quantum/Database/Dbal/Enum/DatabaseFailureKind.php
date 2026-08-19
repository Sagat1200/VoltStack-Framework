<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Enum;

/**
 * Taxonomía uniforme de fallos de DBAL.
 * Todos los DB drivers mapean sus errores nativos a uno de estos 9 valores.
 * Ver DDD-V1-01 §4.
 */
enum DatabaseFailureKind: string
{
    case Configuration = 'configuration';
    case Validation    = 'validation';
    case Capability    = 'capability';
    case Authorization = 'authorization';
    case Timeout       = 'timeout';
    case Concurrency   = 'concurrency';
    case Connectivity  = 'connectivity';
    case Integrity     = 'integrity';
    case Internal      = 'internal';
}
