<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;

/**
 * Fachada de compatibilidad sobre {@see RuntimeManager}.
 *
 * @deprecated Desde la versión 0.1.15. {@see RuntimeManager} es la instancia
 *             canónica del subsistema de runtime. Esta clase se mantiene sólo
 *             para compatibilidad con código legacy y será removida en v1.0.
 *             Migre todas las referencias a {@see RuntimeManager} (binding
 *             `VoltStack\Runtime\RuntimeManager`).
 */
final class RuntimeManagerServer
{
    private readonly RuntimeManager $manager;

    /**
     * @param array<string, RuntimeAdapterInterface> $adapters
     *
     * @deprecated Use {@see RuntimeManager::__construct} o {@see RuntimeManager::createDefault}.
     */
    public function __construct(
        Application $app,
        array $adapters = [],
        ?WorkerFactoryInterface $workerFactory = null,
        ?RuntimeManager $manager = null,
    ) {
        $this->manager = $manager ?? new RuntimeManager($app, $adapters, $workerFactory);
    }

    /**
     * @deprecated Use {@see RuntimeManager::createDefault}.
     */
    public static function createDefault(Application $app): self
    {
        return new self($app, manager: RuntimeManager::createDefault($app));
    }

    /**
     * @deprecated Use {@see RuntimeManager} directamente.
     */
    public function manager(): RuntimeManager
    {
        return $this->manager;
    }

    /**
     * @deprecated Use {@see RuntimeManager::registerAdapter}.
     */
    public function registerAdapter(RuntimeAdapterInterface $adapter): self
    {
        $this->manager->registerAdapter($adapter);

        return $this;
    }

    /**
     * @return list<string>
     *
     * @deprecated Use {@see RuntimeManager::drivers}.
     */
    public function drivers(): array
    {
        return $this->manager->drivers();
    }

    /**
     * @deprecated Use {@see RuntimeManager::run}.
     */
    public function run(ApplicationPlan $plan, RuntimeConfiguration $configuration): int
    {
        return $this->manager->run($plan, $configuration);
    }

    /**
     * @deprecated Use {@see RuntimeManager::adapter}.
     */
    public function adapter(string $driver): RuntimeAdapterInterface
    {
        return $this->manager->adapter($driver);
    }
}
