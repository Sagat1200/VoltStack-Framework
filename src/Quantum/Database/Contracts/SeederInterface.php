<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

use VoltStack\Framework\Application;

/**
 * Contract for a deterministic seed routine that populates the database.
 *
 * Seeders are usually file-discovered from `database/seeders` and executed
 * by the SeederRunner. They may invoke nested seeders via the abstract base
 * class helper or directly produce records through the EntityManager /
 * Database service surface.
 *
 * Implementations SHOULD be idempotent when possible; when strict uniqueness
 * constraints would be violated the seeder SHOULD short-circuit instead of
 * failing with a duplicate key (the runner wraps each seeder in a transaction
 * so partial inserts are rolled back automatically when an exception bubbles).
 */
interface SeederInterface
{
    /**
     * Execute the seeder body.
     *
     * @param Application $application the currently-booted application used
     *                                  to resolve scoped services (EntityManager,
     *                                  Database, FactoryRegistry, etc.).
     */
    public function run(Application $application): void;
}
