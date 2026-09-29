<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use RuntimeException;

final class FileOidcJwksCache implements OidcJwksCacheInterface
{
    private const DEFAULT_TTL = 3600;
    private const FILE_NAME = 'jwks_cache.json';

    private readonly string $storagePath;

    public function __construct(
        string $storageDirectory,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
    ) {
        if ($storageDirectory === '') {
            throw new RuntimeException('FileOidcJwksCache storage directory cannot be empty.');
        }

        $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $storageDirectory), DIRECTORY_SEPARATOR);

        if (! is_dir($normalized) && ! @mkdir($normalized, 0755, true) && ! is_dir($normalized)) {
            throw new RuntimeException(sprintf(
                'FileOidcJwksCache storage directory does not exist and cannot be created: %s',
                $normalized,
            ));
        }

        $this->storagePath = $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getKey(string $kid): ?array
    {
        if (trim($kid) === '') {
            return null;
        }

        $data = $this->readStore();
        if ($data === null) {
            return null;
        }

        if ($this->hasExpired($data['fetched_at'] ?? 0)) {
            return null;
        }

        $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        if (! is_array($keys[$kid] ?? null)) {
            return null;
        }

        return $keys[$kid];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    public function saveKey(string $kid, array $jwk): void
    {
        if (trim($kid) === '') {
            return;
        }

        $existing = $this->readStore() ?? [
            'fetched_at' => 0,
            'keys' => [],
        ];

        $now = time();
        $existing['keys'][$kid] = $jwk;
        if (($existing['fetched_at'] ?? 0) <= 0) {
            $existing['fetched_at'] = $now;
        }

        $this->writeStore($existing);
    }

    public function markFetchedNow(int $ts = 0): void
    {
        $existing = $this->readStore() ?? [
            'fetched_at' => 0,
            'keys' => [],
        ];

        $existing['fetched_at'] = $ts > 0 ? $ts : time();
        $this->writeStore($existing);
    }

    public function getFetchedAt(): int
    {
        $data = $this->readStore();
        return is_array($data) && isset($data['fetched_at']) && is_int($data['fetched_at']) ? (int) $data['fetched_at'] : 0;
    }

    public function getTtlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function hasExpired(int $nowTs = 0): bool
    {
        if ($this->ttlSeconds <= 0) {
            return false;
        }
        $fetchedAt = $this->getFetchedAt();
        $now = $nowTs > 0 ? $nowTs : time();
        return $fetchedAt > 0 && ($now - $fetchedAt) > $this->ttlSeconds;
    }

    public function clear(): void
    {
        $path = $this->filePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{fetched_at?: int, keys?: array<string, array<string, mixed>>}|null
     */
    private function readStore(): ?array
    {
        $path = $this->filePath();
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array{fetched_at?: int, keys?: array<string, array<string, mixed>>} $data
     */
    private function writeStore(array $data): void
    {
        $path = $this->filePath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create JWKS cache directory [%s].', $dir));
        }

        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        $written = @file_put_contents($tmp, $encoded, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to write JWKS cache file [%s].', $path));
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to finalize JWKS cache file [%s].', $path));
        }
    }

    private function filePath(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . self::FILE_NAME;
    }
}
