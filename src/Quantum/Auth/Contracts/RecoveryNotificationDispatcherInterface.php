<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryNotification;

interface RecoveryNotificationDispatcherInterface
{
    public function dispatch(RecoveryNotification $notification): bool;
}
