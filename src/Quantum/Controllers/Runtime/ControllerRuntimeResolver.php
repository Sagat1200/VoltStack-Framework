<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use Quantum\Controllers\Runtime\ControllerRuntimeOptions;
use Quantum\Controllers\Runtime\ControllerRuntimeResolverInterface;

final readonly class ControllerRuntimeResolver implements ControllerRuntimeResolverInterface
{
    public function __construct(private ControllerMetadataResolverInterface $metadata) {}

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

        $timeoutsEnabled = $bag->get('controller.lifecycle.timeouts.enabled', true);

        if (! is_bool($timeoutsEnabled)) {
            $timeoutsEnabled = (bool) $timeoutsEnabled;
        }

        $timeoutDefaultSeconds = $bag->get('controller.lifecycle.timeouts.default');

        if ($timeoutDefaultSeconds === null) {
            $timeoutDefaultSeconds = null;
        } elseif (is_numeric($timeoutDefaultSeconds)) {
            $timeoutDefaultSeconds = (float) $timeoutDefaultSeconds;
        } else {
            $timeoutDefaultSeconds = null;
        }

        if (is_float($timeoutDefaultSeconds) && $timeoutDefaultSeconds <= 0) {
            $timeoutDefaultSeconds = null;
        }

        return new ControllerRuntimeOptions(
            lifecycleMode: strtolower(trim($lifecycleMode)),
            compilationEnabled: $compilationEnabled,
            compilationArtifactsFormat: strtolower(trim($format)),
            timeoutsEnabled: $timeoutsEnabled,
            timeoutDefaultSeconds: $timeoutDefaultSeconds,
        );
    }
}
