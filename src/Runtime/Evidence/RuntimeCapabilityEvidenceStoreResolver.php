<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;

final class RuntimeCapabilityEvidenceStoreResolver
{
    public function resolveForDriver(
        Application $app,
        string $driver,
        ?string $baseDirectory = null,
    ): RuntimeCapabilityEvidenceStore {
        $rootPath = $this->rootPath($app, $driver, $baseDirectory);

        return new RuntimeCapabilityEvidenceStore(
            manifest: new BuildManifest($rootPath),
            storageRoot: $rootPath,
        );
    }

    public function rootPath(
        Application $app,
        string $driver,
        ?string $baseDirectory = null,
    ): string {
        $driver = strtolower(trim($driver));
        $baseDirectory = trim((string) ($baseDirectory ?? $app->config('runtime.evidence.path', 'storage/framework/runtime-evidence')));

        if ($baseDirectory === '') {
            $baseDirectory = 'storage/framework/runtime-evidence';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $baseDirectory);

        if ($this->isAbsolutePath($normalized)) {
            $rootPath = rtrim($normalized, '\\/');
        } else {
            $rootPath = rtrim($app->basePath($normalized), '\\/');
        }

        return $rootPath . DIRECTORY_SEPARATOR . $driver;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
