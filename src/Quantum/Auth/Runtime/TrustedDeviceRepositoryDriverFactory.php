<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\TrustedDeviceRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\FileTrustedDeviceRepository;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;
use RuntimeException;

final class TrustedDeviceRepositoryDriverFactory implements TrustedDeviceRepositoryDriverFactoryInterface
{
    /**
     * @var array<string, callable(array<string, mixed>): TrustedDeviceRepositoryInterface>
     */
    private array $drivers = [];

    public function __construct()
    {
        $this->registerDriver('memory', static fn (): InMemoryTrustedDeviceRepository => new InMemoryTrustedDeviceRepository());

        $this->registerDriver('file', static function (array $config): FileTrustedDeviceRepository {
            $directory = isset($config['storage_path']) && is_string($config['storage_path']) && trim($config['storage_path']) !== ''
                ? $config['storage_path']
                : (sys_get_temp_dir() . '/voltstack-auth-trusted-devices');

            return new FileTrustedDeviceRepository($directory);
        });
    }

    public function registerDriver(string $alias, callable $factory): void
    {
        $alias = trim($alias);

        if ($alias === '') {
            throw new RuntimeException('Cannot register a trusted device repository driver with an empty alias.');
        }

        $this->drivers[$alias] = $factory;
    }

    public function hasDriver(string $alias): bool
    {
        return isset($this->drivers[trim($alias)]);
    }

    public function make(string $alias, array $config = []): TrustedDeviceRepositoryInterface
    {
        $alias = trim($alias);

        if ($alias === '' || ! isset($this->drivers[$alias])) {
            throw new RuntimeException(sprintf(
                'Unknown trusted device repository driver [%s]. Known drivers: [%s].',
                $alias,
                implode(', ', array_keys($this->drivers)),
            ));
        }

        $driver = $this->drivers[$alias]($config);
        $interface = TrustedDeviceRepositoryInterface::class;

        if (! $driver instanceof $interface) {
            throw new RuntimeException(sprintf(
                'Trusted device repository driver [%s] must produce instances of %s; got [%s].',
                $alias,
                $interface,
                get_debug_type($driver),
            ));
        }

        return $driver;
    }
}
