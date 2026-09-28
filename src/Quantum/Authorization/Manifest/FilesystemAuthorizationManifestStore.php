<?php

declare(strict_types=1);

namespace Quantum\Authorization\Manifest;

use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayloadFactory;
use RuntimeException;

final class FilesystemAuthorizationManifestStore implements AuthorizationManifestStoreInterface
{
    private readonly string $storagePath;

    public function __construct(string $storagePath)
    {
        $path = rtrim($storagePath, '\\/');

        if ($path === '') {
            throw new RuntimeException('Authorization manifest storage path cannot be empty.');
        }

        $this->storagePath = $path;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function has(string $fingerprint): bool
    {
        return is_file($this->entryPath($fingerprint));
    }

    public function get(string $fingerprint): ?AuthorizationMetadataPayload
    {
        $path = $this->entryPath($fingerprint);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $data = $this->includeSafe($path);

        if ($data === null || ! is_array($data) || ! isset($data['fingerprint'], $data['payload']) || ! is_array($data['payload'])) {
            return null;
        }

        if (! hash_equals((string) $data['fingerprint'], $fingerprint)) {
            return null;
        }

        return AuthorizationMetadataPayloadFactory::fromArray($data['payload']);
    }

    public function put(AuthorizationMetadataPayload $payload): void
    {
        $this->ensureDirectory($this->storagePath);

        $fingerprint = $payload->fingerprint();
        $now = time();
        $existing = $this->readEntry($fingerprint);
        $createdAt = $existing?->createdAt ?? $now;

        $entry = new AuthorizationManifestEntry(
            fingerprint: $fingerprint,
            payload: $payload,
            createdAt: $createdAt,
            updatedAt: $now,
            runtimeMetadata: $existing?->runtimeMetadata ?? [],
        );

        $path = $this->entryPath($fingerprint);
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $contents = "<?php\n\nreturn " . var_export($this->entryToArray($entry), true) . ";\n";

        $written = @file_put_contents($tempPath, $contents, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf('Failed to write authorization manifest entry to temp path [%s].', $tempPath));
        }

        $renamed = @rename($tempPath, $path);

        if (! $renamed) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf('Failed to atomically rename authorization manifest entry to [%s].', $path));
        }

        @chmod($path, 0666 & ~umask());
    }

    public function forget(string $fingerprint): void
    {
        $path = $this->entryPath($fingerprint);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function clear(): int
    {
        if (! is_dir($this->storagePath)) {
            return 0;
        }

        $deleted = 0;

        foreach (glob($this->storagePath . DIRECTORY_SEPARATOR . 'authz_*.php') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function readEntry(string $fingerprint): ?AuthorizationManifestEntry
    {
        $path = $this->entryPath($fingerprint);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $data = $this->includeSafe($path);

        if ($data === null || ! is_array($data) || ! isset($data['fingerprint'], $data['payload'], $data['created_at'], $data['updated_at'])) {
            return null;
        }

        if (! is_array($data['payload'])) {
            return null;
        }

        return new AuthorizationManifestEntry(
            fingerprint: (string) $data['fingerprint'],
            payload: AuthorizationMetadataPayloadFactory::fromArray($data['payload']),
            createdAt: (int) $data['created_at'],
            updatedAt: (int) $data['updated_at'],
            runtimeMetadata: isset($data['runtime_metadata']) && is_array($data['runtime_metadata']) ? $data['runtime_metadata'] : [],
        );
    }

    private function entryPath(string $fingerprint): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . 'authz_' . $fingerprint . '.php';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function includeSafe(string $path): ?array
    {
        $level = error_reporting(0);

        try {
            /** @psalm-suppress UnresolvableInclude */
            $result = include $path;
        } finally {
            error_reporting($level);
        }

        if (! is_array($result)) {
            return null;
        }

        return $result;
    }

    /**
     * @return array{fingerprint:string,payload:array{public:bool,requirements:list<array{ability:string,subject:mixed,source:string}>,fingerprint:string},created_at:int,updated_at:int,runtime_metadata:array<string,mixed>}
     */
    private function entryToArray(AuthorizationManifestEntry $entry): array
    {
        return $entry->toArray();
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $created = @mkdir($dir, 0777, true);

        if (! $created && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create authorization manifest storage directory [%s].', $dir));
        }
    }
}
