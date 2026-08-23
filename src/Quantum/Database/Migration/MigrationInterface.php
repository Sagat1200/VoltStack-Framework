<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

interface MigrationInterface
{
    public function version(): string;

    public function description(): string;

    public function up(ConnectionInterface $connection): void;

    public function down(ConnectionInterface $connection): void;

    public function isTransactional(): bool;
}