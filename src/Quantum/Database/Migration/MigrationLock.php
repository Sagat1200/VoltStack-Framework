<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

final class MigrationLock
{
    /**
     * @var array<string, true>
     */
    private static array $heldPaths = [];

    public function __construct(
        private readonly string $path,
    ) {
    }

    public static function forConnection(
        string $locksRoot,
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
            'migration-%s-%s.lock',
            self::sanitizeSegment($connectionName ?? 'default'),
            substr(hash('sha256', $scope), 0, 16),
        );

        return new self(rtrim($locksRoot, '\\/') . DIRECTORY_SEPARATOR . $filename);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function acquire(): MigrationLockLease
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create migration lock directory [%s].', $directory));
        }

        if (isset(self::$heldPaths[$this->path])) {
            throw new \RuntimeException(sprintf('Migration lock is already held in this process [%s].', $this->path));
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open migration lock file [%s].', $this->path));
        }

        $locked = @flock($handle, \LOCK_EX | \LOCK_NB);
        if ($locked !== true) {
            @fclose($handle);
            throw new \RuntimeException(sprintf('Migration lock is already held [%s].', $this->path));
        }

        self::$heldPaths[$this->path] = true;

        $payload = json_encode([
            'pid' => getmypid() ?: null,
            'acquired_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
        ], JSON_THROW_ON_ERROR);

        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, $payload . PHP_EOL);
        @fflush($handle);

        return new MigrationLockLease(
            path: $this->path,
            handle: $handle,
            releaseCallback: function (string $path): void {
                unset(self::$heldPaths[$path]);
            },
        );
    }

    private static function sanitizeSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? 'default';
        $sanitized = trim($sanitized, '-');

        return $sanitized !== '' ? $sanitized : 'default';
    }
}
