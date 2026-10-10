<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryAuditLoggerInterface;

final class FileRecoveryAuditLogger implements RecoveryAuditLoggerInterface
{
    private ?string $directory = null;

    public function __construct(
        private readonly string $storagePath,
    ) {}

    public function log(RecoveryAuditEvent $event): bool
    {
        return $this->appendLine($event->toArray());
    }

    public function logMany(iterable $events): int
    {
        $written = 0;

        foreach ($events as $event) {
            if (! $event instanceof RecoveryAuditEvent) {
                continue;
            }

            if ($this->appendLine($event->toArray())) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return array<int, RecoveryAuditEvent>
     */
    public function readAll(): array
    {
        if (! is_file($this->storagePath)) {
            return [];
        }

        $lines = file($this->storagePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines)) {
            return [];
        }

        $events = [];

        foreach ($lines as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $events[] = RecoveryAuditEvent::fromArray($decoded);
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendLine(array $payload): bool
    {
        if ($this->directory === null) {
            $directory = dirname($this->storagePath);

            if ($directory !== '' && ! is_dir($directory)) {
                try {
                    mkdir($directory, 0777, true);
                } catch (\Throwable) {
                    return false;
                }
            }

            $this->directory = $directory;
        }

        try {
            $line = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (\Throwable) {
            return false;
        }

        return @file_put_contents($this->storagePath, $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
