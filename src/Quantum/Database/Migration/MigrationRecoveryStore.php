<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class MigrationRecoveryStore
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public static function forConnection(
        string $root,
        ?string $connectionName,
        ConnectionInterface $connection,
        string $repositoryTable,
    ): self {
        $driver = $connection->getDriverInfo();
        $scope = implode('|', [
            trim((string) $connectionName) !== '' ? trim((string) $connectionName) : 'default',
            $driver->driverName,
            $driver->databaseName !== '' ? $driver->databaseName : 'default',
            $repositoryTable,
        ]);

        $filename = sprintf(
            'migration-%s-%s-recovery.json',
            self::sanitizeSegment($connectionName ?? 'default'),
            substr(hash('sha256', $scope), 0, 16),
        );

        return new self(rtrim($root, '\\/') . DIRECTORY_SEPARATOR . $filename);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function load(): ?MigrationRecoveryState
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode migration recovery file [%s].', $this->path), 0, $e);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Invalid migration recovery payload [%s].', $this->path));
        }

        return MigrationRecoveryState::fromArray($payload);
    }

    public function save(MigrationRecoveryState $state): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create migration recovery directory [%s].', $directory));
        }

        $payload = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        @file_put_contents($this->path, $payload . PHP_EOL);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private static function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? 'default';
        $sanitized = trim($sanitized, '-');

        return $sanitized !== '' ? $sanitized : 'default';
    }
}
