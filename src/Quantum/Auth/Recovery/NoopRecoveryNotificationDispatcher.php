<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface;

final class NoopRecoveryNotificationDispatcher implements RecoveryNotificationDispatcherInterface
{
    public function dispatch(RecoveryNotification $notification): bool
    {
        return true;
    }
}
