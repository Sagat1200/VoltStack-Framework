<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

final readonly class ConnectionDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $name,
        public string $driver,
        public string $database,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $charset = null,
        public string $prefix = '',
        public ?string $platform = null,
        public ?string $dialect = null,
        public array $options = [],
    ) {
    }

    public function platformId(): string
    {
        if ($this->platform !== null && trim($this->platform) !== '') {
            return strtolower(trim($this->platform));
        }

        return match (strtolower($this->driver)) {
            'sqlite', 'sqlite3', 'pdo.sqlite' => 'sqlite',
            'pgsql', 'postgres', 'postgresql', 'pdo.pgsql' => 'pgsql',
            'mysql', 'mariadb', 'pdo.mysql' => 'mysql',
            default => strtolower($this->driver),
        };
    }

    public function dialectId(): string
    {
        if ($this->dialect !== null && trim($this->dialect) !== '') {
            return strtolower(trim($this->dialect));
        }

        return $this->platformId();
    }
}
