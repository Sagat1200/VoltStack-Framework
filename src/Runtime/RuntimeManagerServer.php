<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use InvalidArgumentException;
use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Adapters\FrankenPhpRuntimeAdapter;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;

final class RuntimeManagerServer
{
    /**
     * @param array<string, RuntimeAdapterInterface> $adapters
     */
    public function __construct(
        private readonly Application $app,
        private array $adapters = [],
        private ?WorkerFactoryInterface $workerFactory = null,
    ) {
        if ($this->adapters === []) {
            $default = new FrankenPhpRuntimeAdapter();
            $this->adapters[$default->id()] = $default;
        }
    }

    public static function createDefault(Application $app): self
    {
        return new self($app);
    }

    public function registerAdapter(RuntimeAdapterInterface $adapter): self
    {
        $this->adapters[$adapter->id()] = $adapter;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function drivers(): array
    {
        return array_values(array_keys($this->adapters));
    }

    public function run(ApplicationPlan $plan, RuntimeConfiguration $configuration): int
    {
        $adapter = $this->adapter($configuration->driver());

        return $adapter->run(
            plan: $plan,
            factory: $this->workerFactory ?? $this->app->make(WorkerFactoryInterface::class),
            configuration: $configuration,
        );
    }

    public function adapter(string $driver): RuntimeAdapterInterface
    {
        $driver = strtolower(trim($driver));

        if ($driver === '' || ! isset($this->adapters[$driver])) {
            throw new InvalidArgumentException(sprintf(
                'Runtime adapter [%s] is not registered.',
                $driver === '' ? '(empty)' : $driver,
            ));
        }

        return $this->adapters[$driver];
    }
}
