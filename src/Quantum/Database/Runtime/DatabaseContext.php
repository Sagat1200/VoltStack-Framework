<?php

declare(strict_types=1);

namespace Quantum\Database\Runtime;

use Quantum\Database\Config\DatabaseConfiguration;

final readonly class DatabaseContext
{
    public function __construct(
        private DatabaseExecutionScope $scope,
        private DatabaseConfiguration $configuration,
    ) {
    }

    public function scope(): DatabaseExecutionScope
    {
        return $this->scope;
    }

    public function configuration(): DatabaseConfiguration
    {
        return $this->configuration;
    }

    public function defaultConnectionName(): string
    {
        return $this->configuration->defaultConnectionName;
    }
}
