<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;

final class RuntimeManagerServer
{
    private readonly RuntimeManager $manager;

    /**
     * @param array<string, RuntimeAdapterInterface> $adapters
     */
    public function __construct(
        Application $app,
        array $adapters = [],
        ?WorkerFactoryInterface $workerFactory = null,
        ?RuntimeManager $manager = null,
    ) {
        $this->manager = $manager ?? new RuntimeManager($app, $adapters, $workerFactory);
    }

    public static function createDefault(Application $app): self
    {
        return new self($app, manager: RuntimeManager::createDefault($app));
    }

    public function manager(): RuntimeManager
    {
        return $this->manager;
    }

    public function registerAdapter(RuntimeAdapterInterface $adapter): self
    {
        $this->manager->registerAdapter($adapter);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return $this->manager->drivers();
    }

    public function run(ApplicationPlan $plan, RuntimeConfiguration $configuration): int
    {
        return $this->manager->run($plan, $configuration);
    }

    public function adapter(string $driver): RuntimeAdapterInterface
    {
        return $this->manager->adapter($driver);
    }
}
