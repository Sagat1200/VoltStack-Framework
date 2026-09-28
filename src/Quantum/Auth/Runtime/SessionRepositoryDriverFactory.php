<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\SessionRepositoryDriverFactoryInterface;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use RuntimeException;

final class SessionRepositoryDriverFactory implements SessionRepositoryDriverFactoryInterface
{
    /**
     * @var array<string, callable(array<string, mixed>): AuthenticationSessionRepositoryInterface>
     */
    private array $drivers = [];

    public function __construct()
    {
        $this->registerDriver('memory', static function (array $config): InMemoryAuthenticationSessionRepository {
            $retention = isset($config['recovery_retention_seconds']) && is_numeric($config['recovery_retention_seconds'])
                ? (int) $config['recovery_retention_seconds']
                : 604800;

            return new InMemoryAuthenticationSessionRepository($retention);
        });

        $this->registerDriver('file', static function (array $config): FileAuthenticationSessionRepository {
            $directory = isset($config['storage_path']) && is_string($config['storage_path']) && trim($config['storage_path']) !== ''
                ? $config['storage_path']
                : (sys_get_temp_dir() . '/voltstack-auth-sessions');
            $retention = isset($config['recovery_retention_seconds']) && is_numeric($config['recovery_retention_seconds'])
                ? (int) $config['recovery_retention_seconds']
                : 604800;

            return new FileAuthenticationSessionRepository($directory, $retention);
        });
    }

    public function registerDriver(string $alias, callable $factory): void
    {
        $alias = trim($alias);

        if ($alias === '') {
            throw new RuntimeException('Cannot register a session repository driver with an empty alias.');
        }

        $this->drivers[$alias] = $factory;
    }

    public function hasDriver(string $alias): bool
    {
        return isset($this->drivers[trim($alias)]);
    }

    public function make(string $alias, array $config = []): AuthenticationSessionRepositoryInterface
    {
        $alias = trim($alias);

        if ($alias === '' || ! isset($this->drivers[$alias])) {
            throw new RuntimeException(sprintf(
                'Unknown session repository driver [%s]. Known drivers: [%s].',
                $alias,
                implode(', ', array_keys($this->drivers)),
            ));
        }

        $driver = $this->drivers[$alias]($config);
        $interface = AuthenticationSessionRepositoryInterface::class;

        if (! $driver instanceof $interface) {
            throw new RuntimeException(sprintf(
                'Session repository driver [%s] must produce instances of %s; got [%s].',
                $alias,
                $interface,
                get_debug_type($driver),
            ));
        }

        return $driver;
    }
}
