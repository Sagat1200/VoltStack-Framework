<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Diagnostics;

use Quantum\Config\ConfigRepository;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use VoltStack\Framework\Application;

final class ExceptionPlanStatusInspector
{
    public function inspect(Application $app): ExceptionPlanStatusReport
    {
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $rawConfig = $app->config('exceptions', []);
        $exceptionConfig = is_array($rawConfig) ? $rawConfig : [];

        if ($config->has('exceptions')) {
            $exceptionConfig = $config->get('exceptions', []);
            $exceptionConfig = is_array($exceptionConfig) ? $exceptionConfig : [];
        }

        /** @var ExceptionCompilationPlan $effectivePlan */
        $effectivePlan = $app->make(ExceptionPlanCompiler::class)->compile($exceptionConfig);
        $store = $app->make(ExceptionPlanStore::class);
        $artifactPath = $store->currentPath();
        $publishedArtifactExists = is_file($artifactPath);
        $publishedPlan = null;
        $publishedArtifactError = null;
        $publishedCompatible = false;

        if ($publishedArtifactExists) {
            try {
                $publishedPlan = $store->load();

                if ($publishedPlan !== null) {
                    $publishedPlan->assertCompatibleWith(
                        expectedRuntime: $effectivePlan->runtime(),
                        expectedPhpRuntimeVersion: PHP_VERSION,
                    );
                    $publishedCompatible = true;
                }
            } catch (ExceptionCompilationException $exception) {
                $publishedArtifactError = $exception->getMessage();
                $publishedPlan = null;
            }
        }

        return new ExceptionPlanStatusReport(
            effectivePlan: $effectivePlan,
            publishedPlan: $publishedPlan,
            artifactPath: $artifactPath,
            publishedArtifactExists: $publishedArtifactExists,
            publishedArtifactError: $publishedArtifactError,
            publishedCompatible: $publishedCompatible,
        );
    }
}
