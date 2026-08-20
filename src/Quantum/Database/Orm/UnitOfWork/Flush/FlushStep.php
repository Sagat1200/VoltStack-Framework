<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Flush;

use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;

final readonly class FlushStep
{
    public function __construct(
        public FlushStepType           $type,
        public object                  $entity,
        public CompiledEntityMetadata  $meta,
        public ?ChangeSet              $changeSet,
        /** orden topológico (0 primero) */
        public int                     $order,
        /** spl_object_id para deduplicación */
        public int                     $oid,
    ) {}
}
