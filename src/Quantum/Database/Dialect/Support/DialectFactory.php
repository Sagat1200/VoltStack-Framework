<?php declare(strict_types=1);

namespace Quantum\Database\Dialect\Support;

use Quantum\Database\Dialect\DialectInterface;

/**
 * Factory simple → instancia Dialect por driver name. V1 lista 4 dialects.
 */
final class DialectFactory
{
    /** @var array<string, DialectInterface> */
    private static array $singletons = [];

    /** @param string $driver one of 'pgsql','mysql','mariadb','sqlite' */
    public static function forDriver(string $driver): DialectInterface
    {
        $d = strtolower($driver);
        return self::$singletons[$d] ??= match ($d) {
            'pgsql'   => new PgsqlDialect(),
            'mysql'   => new MysqlDialect(),
            'mariadb' => new MariadbDialect(),
            'sqlite'  => new SqliteDialect(),
            default   => throw new \InvalidArgumentException("Unknown DB driver dialect: {$driver}"),
        };
    }
}
