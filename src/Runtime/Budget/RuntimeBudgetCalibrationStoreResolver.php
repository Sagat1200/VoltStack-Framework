<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

use Quantum\Compilation\BuildManifest;
use VoltStack\Framework\Application;

final class RuntimeBudgetCalibrationStoreResolver
{
    public function resolveForDriver(
        Application $app,
        string $driver,
        ?string $baseDirectory = null,
    ): RuntimeBudgetCalibrationStore {
        $rootPath = $this->rootPath($app, $driver, $baseDirectory);

        return new RuntimeBudgetCalibrationStore(
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
        $baseDirectory = trim((string) ($baseDirectory ?? $app->config('runtime.budgets.calibration_path', 'storage/framework/runtime-budget')));

        if ($baseDirectory === '') {
            $baseDirectory = 'storage/framework/runtime-budget';
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
