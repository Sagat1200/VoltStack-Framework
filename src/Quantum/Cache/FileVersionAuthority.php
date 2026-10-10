<?php

declare(strict_types=1);

namespace Quantum\Cache;

use Quantum\Cache\Contracts\VersionAuthorityInterface;

final class FileVersionAuthority implements VersionAuthorityInterface
{
    private const ENVELOPE_VERSION = 2;

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
        return 'v' . $this->readEnvelope($this->normalizeScope($scope))['version'];
    }

    public function bump(string $scope, ?string $reason = null): string
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
            $envelope = $this->readEnvelope($normalizedScope);
            $nextVersion = $envelope['version'] + 1;
            $this->writeEnvelope(
                $normalizedScope,
                $nextVersion,
                is_string($reason) ? trim($reason) : '',
                $envelope['bump_counter'] + 1,
            );

            return 'v' . $nextVersion;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * @return array{envelope:int,scope:string,version:int<1,max>,updated_at:string,bump_counter:int<0,max>,last_reason:string,last_bump_at:string}
     */
    public function readEnvelope(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $path = $this->versionPathFor($scope);

        if (! is_file($path)) {
            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => 1,
                'updated_at' => '',
                'bump_counter' => 0,
                'last_reason' => '',
                'last_bump_at' => '',
            ];
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => 1,
                'updated_at' => '',
                'bump_counter' => 0,
                'last_reason' => '',
                'last_bump_at' => '',
            ];
        }

        $payload = json_decode($contents, true);

        if (! is_array($payload)) {
            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => 1,
                'updated_at' => '',
                'bump_counter' => 0,
                'last_reason' => '',
                'last_bump_at' => '',
            ];
        }

        $envelope = (int) ($payload['envelope'] ?? 0);

        if ($envelope !== self::ENVELOPE_VERSION) {
            $legacyVersion = isset($payload['version']) && is_int($payload['version']) && $payload['version'] > 0
                ? $payload['version']
                : 1;

            return [
                'envelope' => self::ENVELOPE_VERSION,
                'scope' => $scope,
                'version' => $legacyVersion,
                'updated_at' => is_string($payload['updated_at'] ?? null) ? (string) $payload['updated_at'] : '',
                'bump_counter' => max(0, $legacyVersion - 1),
                'last_reason' => '',
                'last_bump_at' => is_string($payload['updated_at'] ?? null) ? (string) $payload['updated_at'] : '',
            ];
        }

        $version = isset($payload['version']) && is_int($payload['version']) && $payload['version'] > 0
            ? $payload['version']
            : 1;
        $bumpCounter = isset($payload['bump_counter']) && is_int($payload['bump_counter']) && $payload['bump_counter'] >= 0
            ? $payload['bump_counter']
            : max(0, $version - 1);

        return [
            'envelope' => self::ENVELOPE_VERSION,
            'scope' => is_string($payload['scope'] ?? null) ? (string) $payload['scope'] : $scope,
            'version' => $version,
            'updated_at' => is_string($payload['updated_at'] ?? null) ? (string) $payload['updated_at'] : '',
            'bump_counter' => $bumpCounter,
            'last_reason' => is_string($payload['last_reason'] ?? null) ? (string) $payload['last_reason'] : '',
            'last_bump_at' => is_string($payload['last_bump_at'] ?? null)
                ? (string) $payload['last_bump_at']
                : (is_string($payload['updated_at'] ?? null) ? (string) $payload['updated_at'] : ''),
        ];
    }

    private function writeEnvelope(string $scope, int $version, string $reason, int $bumpCounter): void
    {
        $path = $this->versionPathFor($scope);
        $directory = dirname($path);
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $timestamp = date('c');

        $this->ensureDirectory($directory);

        $payload = json_encode([
            'envelope' => self::ENVELOPE_VERSION,
            'scope' => $scope,
            'version' => $version,
            'updated_at' => $timestamp,
            'bump_counter' => $bumpCounter,
            'last_reason' => $reason,
            'last_bump_at' => $timestamp,
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
