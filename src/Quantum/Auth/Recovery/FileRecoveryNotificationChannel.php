<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationChannelInterface;
use RuntimeException;

/**
 * Canal de notificación que escribe en un archivo JSONL durable.
 *
 * Es el equivalente canalizado del histórico FileRecoveryNotificationDispatcher,
 * pero devuelve un RecoveryNotificationDeliveryResult para poder integrarse
 * en la pipeline de retry/backoff del dispatcher compuesto.
 */
final class FileRecoveryNotificationChannel implements RecoveryNotificationChannelInterface
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        $directory = dirname($this->storagePath());

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create recovery notification directory [%s].',
                $directory,
            ));
        }
    }

    public function channelName(): string
    {
        return 'file';
    }

    public function send(RecoveryNotification $notification): RecoveryNotificationDeliveryResult
    {
        $path = $this->storagePath();

        try {
            $payload = json_encode(
                $notification->toArray(),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            $written = @file_put_contents($path, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);

            if ($written === false) {
                return RecoveryNotificationDeliveryResult::failure(
                    channel: 'file',
                    destination: $notification->destination,
                    errorMessage: 'file_put_contents failed',
                );
            }

            return RecoveryNotificationDeliveryResult::success(
                channel: 'file',
                destination: $notification->destination,
                providerResponse: $path,
            );
        } catch (\Throwable $e) {
            return RecoveryNotificationDeliveryResult::failure(
                channel: 'file',
                destination: $notification->destination,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function storagePath(): string
    {
        $path = trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $this->storagePath));

        if ($path === '') {
            throw new RuntimeException('Recovery notification storage path cannot be empty.');
        }

        return $path;
    }
}
