<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface;
use RuntimeException;

final class FileRecoveryNotificationDispatcher implements RecoveryNotificationDispatcherInterface
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

    public function dispatch(RecoveryNotification $notification): bool
    {
        $payload = json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return @file_put_contents($this->storagePath(), $payload . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
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
