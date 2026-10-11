<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryNotification;
use Quantum\Auth\Recovery\RecoveryNotificationDeliveryResult;

/**
 * Canal de entrega de notificaciones de recovery.
 *
 * Cada canal sabe entregar una notificación por un medio concreto
 * (archivo, webhook HTTP, email, SMS, push, etc.) y devuelve un
 * resultado detallado para audit y retry.
 */
interface RecoveryNotificationChannelInterface
{
    /**
     * Nombre canónico del canal (p.ej. "file", "webhook", "email").
     */
    public function channelName(): string;

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult;
}
