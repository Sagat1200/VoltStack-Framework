<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

final readonly class ControllerRuntimeOptions
{
    public function __construct(
        public string $lifecycleMode,
        public bool $compilationEnabled,
        public string $compilationArtifactsFormat,
        public bool $timeoutsEnabled,
        public ?float $timeoutDefaultSeconds,
    ) {}
}