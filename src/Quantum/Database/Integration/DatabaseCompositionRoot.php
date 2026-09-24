<?php

declare(strict_types=1);

namespace Quantum\Database\Integration;

use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Runtime\DatabaseExecutionScopeFactory;

final readonly class DatabaseCompositionRoot
{
    public function __construct(
        private DatabaseConfiguration $configuration,
        private DatabaseExecutionScopeFactory $executionScopeFactory,
    ) {
    }

    public function configuration(): DatabaseConfiguration
    {
        return $this->configuration;
    }

    public function executionScopeFactory(): DatabaseExecutionScopeFactory
    {
        return $this->executionScopeFactory;
    }
}
