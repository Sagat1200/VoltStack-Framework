<?php

declare(strict_types=1);

namespace Quantum\Cache;

use DateInterval;
use DateTimeInterface;
use Quantum\Cache\Concerns\InteractsWithTime;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\StoreInterface;

final class FileStore implements StoreInterface
{
    use InteractsWithTime;

    public function __construct(
        private readonly string $path,
        private readonly string $prefix = 'voltstack',
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $payload = $this->read($key);

        if ($payload === null) {
            return $default;
        }

        return $payload['value'];
    }

    public function put(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $expiresAt = $this->expirationTimestamp($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->nowUnixSeconds()) {
            return $this->forget($key);
        }

        return $this->write($key, [
            'expires_at' => $expiresAt,
            'value' => $value,
        ]);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->write($key, [
            'expires_at' => null,
            'value' => $value,
        ]);
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function forget(string $key): bool
    {
        $path = $this->pathFor($key);

        return ! is_file($path) || unlink($path);
    }

    public function flush(): bool
    {
        if (! is_dir($this->path)) {
            return true;
        }

        return $this->deleteDirectoryContents($this->path);
    }

    /**
     * @return array{expires_at: int|null, value: mixed}|null
     */
    private function read(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        $payload = @unserialize($contents);

        if (! is_array($payload) || ! array_key_exists('value', $payload)) {
            return null;
        }

        $expiresAt = $payload['expires_at'] ?? null;

        if (is_int($expiresAt) && $expiresAt <= $this->nowUnixSeconds()) {
            $this->forget($key);

            return null;
        }

        return [
            'expires_at' => is_int($expiresAt) ? $expiresAt : null,
            'value' => $payload['value'],
        ];
    }

    /**
     * @param array{expires_at: int|null, value: mixed} $payload
     */
    private function write(string $key, array $payload): bool
    {
        $path = $this->pathFor($key);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return false;
        }

        return file_put_contents($path, serialize($payload), LOCK_EX) !== false;
    }

    private function pathFor(string $key): string
    {
        $hash = sha1($this->prefix . ':' . $key);

        return rtrim($this->path, '\\/')
            . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR
            . $hash
            . '.cache';
    }

    private function deleteDirectoryContents(string $directory): bool
    {
        $items = scandir($directory);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                if (! $this->deleteDirectoryContents($target) || ! rmdir($target)) {
                    return false;
                }

                continue;
            }

            if (! unlink($target)) {
                return false;
            }
        }

        return true;
    }
}
