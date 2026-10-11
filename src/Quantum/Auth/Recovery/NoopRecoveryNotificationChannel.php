<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;

/**
 * Canal no-op: siempre devuelve éxito sin hacer nada.
 *
 * Útil en entornos de prueba o cuando no se quiere enviar ninguna notificación
 * real pero se necesita un canal registrado para el dispatcher compuesto.
 */
final class NoopRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    public function channelName(): string
    {
        return 'noop';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        return RecoveryNotificationDeliveryResult::success(
            channel: 'noop',
            destination: $notification->destination,
        );
    }
}
