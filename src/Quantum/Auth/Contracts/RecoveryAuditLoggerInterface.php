<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryAuditEvent;

interface RecoveryAuditLoggerInterface
{
    public function log(RecoveryAuditEvent $event): bool;

    /**
     * @param iterable<RecoveryAuditEvent> $events
     */
    public function logMany(iterable $events): int;
}
