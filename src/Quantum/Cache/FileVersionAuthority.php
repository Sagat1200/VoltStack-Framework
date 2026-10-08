<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\VersionAuthorityInterface;

final class FileVersionAuthority implements VersionAuthorityInterface
{
    public function __construct(
        private readonly string $storagePath,
    ) {
        if (trim($this->storagePath) === '') {
            throw new \InvalidArgumentException('FileVersionAuthority storage path cannot be empty.');
        }
    }

    public function storagePath(): string
    {
        return rtrim($this->storagePath, '\\/');
    }

    public function currentVersion(string $scope): string
    {
        return 'v' . $this->readVersion($this->normalizeScope($scope));
    }

    public function bump(string $scope): string
    {
        $normalizedScope = $this->normalizeScope($scope);
        $lockPath = $this->lockPathFor($normalizedScope);
        $versionPath = $this->versionPathFor($normalizedScope);

        $this->ensureDirectory(dirname($lockPath));
        $this->ensureDirectory(dirname($versionPath));

        $lock = @fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new \RuntimeException(sprintf(
                'Unable to open version lock file [%s].',
                $lockPath,
            ));
        }

        if (! @flock($lock, LOCK_EX)) {
            @fclose($lock);

            throw new \RuntimeException(sprintf(
                'Unable to acquire version lock for scope [%s].',
                $normalizedScope,
            ));
        }

        try {
            $nextVersion = $this->readVersion($normalizedScope) + 1;
            $this->writeVersion($normalizedScope, $nextVersion);

            return 'v' . $nextVersion;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function readVersion(string $scope): int
    {
        $path = $this->versionPathFor($scope);

        if (! is_file($path)) {
            return 1;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            return 1;
        }

        $payload = json_decode($contents, true);

        if (! is_array($payload)) {
            return 1;
        }

        $version = $payload['version'] ?? 1;

        return is_int($version) && $version > 0 ? $version : 1;
    }

    private function writeVersion(string $scope, int $version): void
    {
        $path = $this->versionPathFor($scope);
        $directory = dirname($path);
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));

        $this->ensureDirectory($directory);

        $payload = json_encode([
            'scope' => $scope,
            'version' => $version,
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($payload)) {
            throw new \RuntimeException(sprintf(
                'Unable to encode version payload for scope [%s].',
                $scope,
            ));
        }

        $written = @file_put_contents($tempPath, $payload, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            throw new \RuntimeException(sprintf(
                'Unable to write temp version file [%s].',
                $tempPath,
            ));
        }

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);

            throw new \RuntimeException(sprintf(
                'Unable to publish version file [%s].',
                $path,
            ));
        }

        @chmod($path, 0666 & ~umask());
    }

    private function versionPathFor(string $scope): string
    {
        $hash = sha1($scope);

        return $this->storagePath()
            . DIRECTORY_SEPARATOR
            . 'versions'
            . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR
            . $hash
            . '.json';
    }

    private function lockPathFor(string $scope): string
    {
        $hash = sha1($scope);

        return $this->storagePath()
            . DIRECTORY_SEPARATOR
            . 'locks'
            . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR
            . $hash
            . '.lock';
    }

    private function normalizeScope(string $scope): string
    {
        $normalized = trim($scope);

        return $normalized === '' ? 'global' : $normalized;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $created = @mkdir($directory, 0777, true);

        if (! $created && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf(
                'Unable to create version authority directory [%s].',
                $directory,
            ));
        }
    }
}
