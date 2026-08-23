<?php

declare(strict_types=1);

namespace Quantum\Database\Seeder;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Factory\FactoryManager;
use VoltStack\Framework\Application;

final class SeedExecutionContext
{
    public function __construct(
        private readonly Application $app,
        private readonly ConnectionInterface $connection,
        private readonly SeedReferenceStore $references,
        private readonly FactoryManager $factories,
        private readonly bool $pretend,
    ) {}

    public function app(): Application
    {
        return $this->app;
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function references(): SeedReferenceStore
    {
        return $this->references;
    }

    public function factories(): FactoryManager
    {
        return $this->factories;
    }

    public function pretend(): bool
    {
        return $this->pretend;
    }
}
