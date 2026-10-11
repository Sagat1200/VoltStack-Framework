<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;

/**
 * Registro de canales de notificación pluggables.
 *
 * Permite registrar canales por nombre y resolverlos en tiempo de ejecución
 * según el campo `channel` de la notificación. El dispatcher compuesto usa
 * este registro para encontrar el canal correcto y aplicar retry/backoff.
 */
final class RecoveryNotificationChannelRegistry
{
    /**
     * @var array<string, RecoveryNotificationChannelInterface>
     */
    private array $channels = [];

    public function register(RecoveryNotificationChannelInterface $channel): void
    {
        $this->channels[$channel->channelName()] = $channel;
    }

    public function resolve(string $name): ?RecoveryNotificationChannelInterface
    {
        $name = trim($name);

        return $this->channels[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[trim($name)]);
    }

    /**
     * @return array<string, RecoveryNotificationChannelInterface>
     */
    public function all(): array
    {
        return $this->channels;
    }
}
