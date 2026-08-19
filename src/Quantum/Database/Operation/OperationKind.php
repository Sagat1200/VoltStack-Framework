<?php declare(strict_types=1);

namespace Quantum\Database\Operation;

/**
 * Tipos de operación del subsistema Database.
 * Operaciones Raw / SQG / ORM insert/update/delete + Hydrate.
 */
enum OperationKind: string
{
    case RawExecute    = 'raw_execute';
    case RawQuery      = 'raw_query';
    case RawPrepare    = 'raw_prepare';
    case SqgSelect     = 'sqg_select';
    case SqgInsert     = 'sqg_insert';
    case SqgUpdate     = 'sqg_update';
    case SqgDelete     = 'sqg_delete';
    case OrmInsert     = 'orm_insert';
    case OrmUpdate     = 'orm_update';
    case OrmDelete     = 'orm_delete';
    case OrmHydrate    = 'orm_hydrate';
    case OrmBulkInsert = 'orm_bulk_insert';
}
