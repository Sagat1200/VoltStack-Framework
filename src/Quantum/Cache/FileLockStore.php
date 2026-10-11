<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\LockStoreInterface;

/**
 * Filesystem-backed lock store. Uses flock() on a per-lock file to guarantee
 * the create-if-absent + expiry operation is indivisible across processes.
 * Each lock file stores a serialized {owner, expires_at_ms} payload.
 */
final class FileLockStore implements LockStoreInterface
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function acquire(string $name, string $owner, int $ttlMs): bool
    {
        $lockFile = $this->lockFile($name);
        $mutex = $this->openMutex($name);

        if ($mutex === null) {
            return false;
        }

        try {
            $now = $this->nowMs();
            $current = $this->readLock($lockFile);

            if ($current !== null && $current['expires_at_ms'] > $now && $current['owner'] !== $owner) {
                return false;
            }

            $written = file_put_contents(
                $lockFile,
                serialize(['owner' => $owner, 'expires_at_ms' => $now + $ttlMs]),
                LOCK_EX,
            );

            return $written !== false;
        } finally {
            $this->closeMutex($mutex);
        }
    }

    public function renew(string $name, string $owner, int $ttlMs): bool
    {
        $lockFile = $this->lockFile($name);
        $mutex = $this->openMutex($name);

        if ($mutex === null) {
            return false;
        }

        try {
            $now = $this->nowMs();
            $current = $this->readLock($lockFile);

            if ($current === null || $current['owner'] !== $owner || $current['expires_at_ms'] <= $now) {
                return false;
            }

            $written = file_put_contents(
                $lockFile,
                serialize(['owner' => $owner, 'expires_at_ms' => $now + $ttlMs]),
                LOCK_EX,
            );

            return $written !== false;
        } finally {
            $this->closeMutex($mutex);
        }
    }

    public function release(string $name, string $owner): bool
    {
        $lockFile = $this->lockFile($name);
        $mutex = $this->openMutex($name);

        if ($mutex === null) {
            return false;
        }

        try {
            $current = $this->readLock($lockFile);

            if ($current === null) {
                return true;
            }

            if ($current['owner'] !== $owner) {
                return false;
            }

            return ! is_file($lockFile) || @unlink($lockFile);
        } finally {
            $this->closeMutex($mutex);
        }
    }

    public function isHeld(string $name): bool
    {
        $current = $this->readLock($this->lockFile($name));

        return $current !== null && $current['expires_at_ms'] > $this->nowMs();
    }

    public function forceRelease(string $name): bool
    {
        $lockFile = $this->lockFile($name);

        return ! is_file($lockFile) || @unlink($lockFile);
    }

    private function lockFile(string $name): string
    {
        $hash = sha1($name);

        return rtrim($this->path, '\\/')
            . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR
            . $hash
            . '.lock';
    }

    private function mutexFile(string $name): string
    {
        return $this->lockFile($name) . '.mutex';
    }

    /**
     * @return resource|null
     */
    private function openMutex(string $name)
    {
        $mutexFile = $this->mutexFile($name);
        $directory = dirname($mutexFile);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return null;
        }

        $mutex = @fopen($mutexFile, 'c+');

        if ($mutex === false) {
            return null;
        }

        if (! @flock($mutex, LOCK_EX)) {
            @fclose($mutex);

            return null;
        }

        return $mutex;
    }

    /**
     * @param resource $mutex
     */
    private function closeMutex($mutex): void
    {
        @flock($mutex, LOCK_UN);
        @fclose($mutex);
    }

    /**
     * @return array{owner: string, expires_at_ms: int}|null
     */
    private function readLock(string $lockFile): ?array
    {
        if (! is_file($lockFile)) {
            return null;
        }

        $contents = @file_get_contents($lockFile);

        if (! is_string($contents)) {
            return null;
        }

        $data = @unserialize($contents);

        if (! is_array($data) || ! isset($data['owner'], $data['expires_at_ms'])) {
            return null;
        }

        return $data;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
