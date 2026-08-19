<?php declare(strict_types=1);

namespace Quantum\Database\Dbal\Value;

/**
 * Información básica del driver.
 */
final readonly class DriverInfo
{
    public function __construct(
        public string $driverName,    // 'pgsql' | 'mysql' | 'sqlite' | 'mariadb'
        public string $serverVersion, // ej: '16.3', '8.4.0', '3.45.1'
        public string $databaseName,
        public ?string $charset = null,
    ) {}
}
