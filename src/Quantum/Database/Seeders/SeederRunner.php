<?php

declare(strict_types=1);

namespace Quantum\Database\Seeders;

use Quantum\Database\Contracts\SeederInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use RuntimeException;
use Throwable;
use VoltStack\Framework\Application;

/**
 * Executes seeders within a properly scoped request lifecycle.
 *
 * Responsibilities:
 *   - locate a target seeder by class-string, by explicit path, or fall
 *     back to the conventional `DatabaseSeeder` discovered from the
 *     `database/seeders` directory,
 *   - wrap the entire run in a database transaction so failures never
 *     leave half-applied seed data,
 *   - flush the EntityManager after the outermost `run()` completes so
 *     seeder bodies can build domain objects freely and rely on a single
 *     batched flush at the end,
 *   - report which seeder was actually executed for CLI/test logging.
 *
 * The runner is intentionally scoped (not singleton) because it depends
 * on the currently-scoped EntityManager + TransactionManager for the
 * wrap-in-transaction + flush guarantees.
 */
final class SeederRunner
{
    public function __construct(
        private readonly Application $application,
        private readonly SeederDiscovery $discovery,
    ) {
    }

    /**
     * Run a specific seeder or discover + run `DatabaseSeeder`.
     *
     * Priority order for resolving what to run:
     *   1. explicit class-string `$class`,
     *   2. explicit directory or file `$path` (discovers all seeders in it,
     *      preferring one named `DatabaseSeeder` when multiple exist),
     *   3. conventional path `database/seeders` -> prefer `DatabaseSeeder`.
     *
     * Returns the actual seeder instance executed so callers can log name.
     *
     * @param class-string<SeederInterface>|null $class
     *
     * @throws RuntimeException when no seeder can be located.
     */
    public function run(?string $class = null, ?string $path = null): SeederInterface
    {
        $seeder = $this->resolveSeeder($class, $path);

        if ($seeder instanceof AbstractSeeder) {
            $seeder->setApplication($this->application);
        }

        $manager = $this->application->make(EntityManagerInterface::class);
        $transactionManager = $this->application->make(TransactionManagerInterface::class);

        $transactionManager->begin();

        try {
            $seeder->run($this->application);
            $manager->flush();
            $transactionManager->commit();
        } catch (Throwable $e) {
            if ($transactionManager->isActive()) {
                $transactionManager->rollback();
            }

            throw $e;
        }

        return $seeder;
    }

    /**
     * @param class-string<SeederInterface>|null $class
     */
    private function resolveSeeder(?string $class, ?string $path): SeederInterface
    {
        if ($class !== null) {
            $instance = $this->application->make($class);

            if (! $instance instanceof SeederInterface) {
                throw new RuntimeException(sprintf(
                    'Resolved seeder class [%s] does not implement SeederInterface.',
                    $class,
                ));
            }

            return $instance;
        }

        $discovered = $this->discovery->discover($path);

        if (count($discovered) === 0) {
            $hint = $path ?? $this->discovery->defaultPath();

            throw new RuntimeException(sprintf(
                'No seeder files were found under [%s]. Create at least a DatabaseSeeder.php returning a SeederInterface instance or pass --class= explicitly.',
                $hint,
            ));
        }

        foreach ($discovered as $item) {
            if ($item->name === 'DatabaseSeeder') {
                return $item->instance;
            }
        }

        if (count($discovered) === 1) {
            return $discovered[0]->instance;
        }

        $names = array_map(static fn(DiscoveredSeeder $d): string => $d->name, $discovered);

        throw new RuntimeException(sprintf(
            'Multiple seeders discovered [%s] but no --class= option was provided and no conventional DatabaseSeeder.php exists. Name your entry seeder DatabaseSeeder or pass --class= explicitly.',
            implode(', ', $names),
        ));
    }
}
