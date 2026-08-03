<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;

final readonly class ControllerRuntimeResolver implements ControllerRuntimeResolverInterface
{
    public function __construct(private ControllerMetadataResolverInterface $metadata)
    {
    }

    public function resolve(ControllerExecution $execution): ControllerRuntimeOptions
    {
        $bag = $this->metadata->resolve($execution)->bag();

        $lifecycleMode = $bag->get('controller.lifecycle.mode', 'auto');

        if (! is_string($lifecycleMode) || trim($lifecycleMode) === '') {
            $lifecycleMode = 'auto';
        }

        $compilationEnabled = $bag->get('controller.compilation.enabled', false);

        if (! is_bool($compilationEnabled)) {
            $compilationEnabled = (bool) $compilationEnabled;
        }

        $format = $bag->get('controller.compilation.artifacts.format', 'php');

        if (! is_string($format) || trim($format) === '') {
            $format = 'php';
        }

        return new ControllerRuntimeOptions(
            lifecycleMode: strtolower(trim($lifecycleMode)),
            compilationEnabled: $compilationEnabled,
            compilationArtifactsFormat: strtolower(trim($format)),
        );
    }
}

