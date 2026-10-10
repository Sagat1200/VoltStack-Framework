<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryAuditLoggerInterface;

final class NoopRecoveryAuditLogger implements RecoveryAuditLoggerInterface
{
    public function log(RecoveryAuditEvent $event): bool
    {
        return true;
    }

    public function logMany(iterable $events): int
    {
        $count = 0;

        foreach ($events as $event) {
            if ($event instanceof RecoveryAuditEvent) {
                $count++;
            }
        }

        return $count;
    }
}
