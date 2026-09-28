<?php

declare(strict_types=1);

namespace Quantum\Database\Seeders;

use Quantum\Database\Contracts\FactoryInterface;
use Quantum\Database\Contracts\SeederInterface;
use Quantum\Database\Factories\FactoryRegistry;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use RuntimeException;
use VoltStack\Framework\Application;

/**
 * Base convenience class for seeders.
 *
 * Provides helpers used by most seed routines:
 *   - `$this->call(SeederInterface|class-string $seeder)` to chain nested
 *     seeders (a `DatabaseSeeder` typically calls 3–5 domain seeders this
 *     way),
 *   - `$this->factory($entityClass, ?int $times)` short-hand returning the
 *     registry factory so seeder bodies read as declarative population
 *     chains instead of explicit registry resolution + flush boilerplate.
 *
 * Seeder implementations MUST only depend on `Application` being passed to
 * `run()` so they are resolvable both from CLI `database:seed` and from
 * programmatic invocations in tests.
 */
abstract class AbstractSeeder implements SeederInterface
{
    private ?Application $application = null;

    abstract public function run(Application $application): void;

    final public function setApplication(Application $application): void
    {
        $this->application = $application;
    }

    /**
     * Execute one or more nested seeders.
     *
     * Accepts either a class-string (resolved via the application container
     * so constructor dependencies are injected) or a pre-built instance.
     *
     * Nested seeders share the same outer transaction started by
     * `SeederRunner` so partial failures rollback deterministically.
     *
     * @param class-string<SeederInterface>|SeederInterface|iterable<class-string<SeederInterface>|SeederInterface> $seeders
     */
    protected function call(string|SeederInterface|iterable $seeders): void
    {
        $app = $this->requireApplication();

        if (is_string($seeders) || $seeders instanceof SeederInterface) {
            $seeders = [$seeders];
        }

        foreach ($seeders as $seeder) {
            $instance = is_string($seeder)
                ? $app->make($seeder)
                : $seeder;

            if (! $instance instanceof SeederInterface) {
                throw new RuntimeException(sprintf(
                    'Invalid nested seeder: expected SeederInterface, got [%s].',
                    get_debug_type($instance),
                ));
            }

            if ($instance instanceof self) {
                $instance->setApplication($app);
            }

            $instance->run($app);
        }
    }

    /**
     * Resolve a factory for the given entity, optionally fixing its count.
     *
     * Usage inside a seeder `run()` body:
     *   `$this->factory(Product::class, 10)->create(['currency' => 'EUR']);`
     *   followed by `$this->flush()` (or rely on SeederRunner doing it).
     *
     * @param class-string $entityClass
     */
    protected function factory(string $entityClass, ?int $times = null): FactoryInterface
    {
        $app = $this->requireApplication();
        $registry = $app->make(FactoryRegistry::class);
        $factory = $registry->for($entityClass);

        if ($times !== null) {
            $factory = $factory->times($times);
        }

        return $factory;
    }

    /**
     * Convenience shortcut to flush the currently-scoped EntityManager.
     *
     * SeederRunner automatically flushes once per outermost seeder so in
     * most cases callers do not need this; it is exposed for the rare
     * cases where a seeder must read-back rows with real IDs assigned by
     * the database before populating related entities.
     */
    protected function flush(): void
    {
        $this->requireApplication()
            ->make(EntityManagerInterface::class)
            ->flush();
    }

    private function requireApplication(): Application
    {
        if ($this->application === null) {
            throw new RuntimeException(sprintf(
                'Seeder [%s] is being used outside of a run() context. Make sure call() is invoked after run() has been entered.',
                static::class,
            ));
        }

        return $this->application;
    }
}
