<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Status;

use Quantum\Bootstrap\Manifest\BootstrapManifestStore;
use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;

final class BootstrapStatusInspector
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function inspect(Application $app, ?string $artifactDirectory = null): BootstrapStatusReport
    {
        $artifactDirectory = $this->normalizeArtifactDirectory($artifactDirectory);
        $alerts = [];
        $generationId = null;
        $manifestPath = null;
        $fingerprint = null;
        $schemaVersion = null;
        $hasActiveGeneration = false;

        $store = new BootstrapManifestStore(
            manifest: new BuildManifest($artifactDirectory),
            storageRoot: $artifactDirectory,
        );

        $current = $store->currentManifest();

        if ($current === null) {
            $alerts[] = 'No hay una generacion bootstrap activa.';
        } else {
            $hasActiveGeneration = true;
            $generationId = $current->generationId();
            $manifestPath = $current->manifestPath();
            $fingerprint = $current->fingerprint();
            $schema = $current->payload()['schema'] ?? null;
            $schemaVersion = is_int($schema) ? $schema : (is_numeric($schema) ? (int) $schema : null);

            if (! is_file($manifestPath)) {
                $alerts[] = 'La generacion activa no tiene manifest accesible en disco.';
            }

            if ($schemaVersion !== 1) {
                $alerts[] = 'La generacion activa usa un schema de manifest no esperado.';
            }
        }

        if (! $app->isBooted()) {
            $alerts[] = 'La aplicacion no esta marcada como booted.';
        }

        return new BootstrapStatusReport(
            environment: $app->environment(),
            booted: $app->isBooted(),
            providerCount: count($app->getProviders()),
            artifactDirectory: $artifactDirectory,
            hasActiveGeneration: $hasActiveGeneration,
            generationId: $generationId,
            manifestPath: $manifestPath,
            fingerprint: $fingerprint,
            schemaVersion: $schemaVersion,
            alerts: $alerts,
        );
    }

    private function normalizeArtifactDirectory(?string $artifactDirectory): string
    {
        $artifactDirectory = trim((string) $artifactDirectory);

        if ($artifactDirectory === '') {
            return $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bootstrap';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifactDirectory);

        if ($this->isAbsolutePath($normalized)) {
            return rtrim($normalized, '\\/');
        }

        return rtrim($this->basePath . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/'), '\\/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
