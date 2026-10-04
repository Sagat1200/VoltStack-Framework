<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Schema\Builtin\CacheConfigSchema;
use Quantum\Config\Schema\Builtin\DatabaseConfigSchema;
use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;
use Quantum\Config\Schema\ConfigSchemaRegistry;
use Quantum\Config\Validation\ConfigValidator;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;

final class ConfigSchemaValidationTest extends TestCase
{
    public function test_schema_registry_registers_and_resolves_cache_schema(): void
    {
        $registry = new ConfigSchemaRegistry();
        $schema = CacheConfigSchema::build();

        $registry->register($schema);

        self::assertTrue($registry->has('cache'));
        self::assertSame($schema, $registry->get('cache'));
    }

    public function test_cache_schema_applies_defaults_and_accepts_current_shape(): void
    {
        $schema = CacheConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'stores' => [
                'file' => [
                    'driver' => 'file',
                    'path' => 'storage/framework/cache/data',
                ],
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('file', $result->data()['default']);
        self::assertSame('voltstack', $result->data()['prefix']);
        self::assertSame('file', $result->data()['stores']['file']['driver']);
    }

    public function test_cache_schema_rejects_unknown_keys_and_invalid_driver(): void
    {
        $schema = CacheConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'default' => 'redis',
            'unexpected' => true,
        ]);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->violations());
        self::assertSame('cache.default', $result->violations()[0]->path());
        self::assertSame('cache.unexpected', $result->violations()[1]->path());
    }

    public function test_repository_can_validate_registered_cache_schema_opt_in(): void
    {
        $repository = new ConfigRepository([
            'cache' => [
                'default' => 'memory',
                'stores' => [
                    'memory' => [
                        'driver' => 'memory',
                    ],
                ],
            ],
        ]);

        $registry = new ConfigSchemaRegistry();
        $registry->register(CacheConfigSchema::build());

        $result = $repository->validateRegistered('cache', $registry);

        self::assertTrue($result->isValid());
        self::assertSame('memory', $result->data()['default']);
        self::assertSame('memory', $result->data()['stores']['memory']['driver']);
        self::assertSame('voltstack', $result->data()['prefix']);
    }

    public function test_database_schema_accepts_framework_shape_and_applies_defaults(): void
    {
        $schema = DatabaseConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'connections' => [
                'default' => [
                    'database' => 'database/database.sqlite',
                ],
                'analytics' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'port' => 5432,
                    'database' => 'analytics',
                    'options' => [
                        'sslmode' => 'prefer',
                    ],
                ],
            ],
            'runtime' => [
                'strict_scope' => true,
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('default', $result->data()['default']);
        self::assertSame('sqlite', $result->data()['connections']['default']['driver']);
        self::assertSame('pgsql', $result->data()['connections']['analytics']['driver']);
        self::assertSame(5432, $result->data()['connections']['analytics']['port']);
        self::assertSame(['sslmode' => 'prefer'], $result->data()['connections']['analytics']['options']);
        self::assertTrue($result->data()['runtime']['strict_scope']);
        self::assertTrue($result->data()['telemetry']['enabled']);
    }

    public function test_database_schema_rejects_invalid_driver_and_runtime_type(): void
    {
        $schema = DatabaseConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'connections' => [
                'default' => [
                    'driver' => 'oracle',
                    'database' => 'analytics',
                ],
            ],
            'runtime' => [
                'strict_scope' => 'yes',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->violations());
        self::assertSame('database.connections.default.driver', $result->violations()[0]->path());
        self::assertSame('database.runtime.strict_scope', $result->violations()[1]->path());
    }

    public function test_repository_can_validate_registered_database_schema_and_feed_provider(): void
    {
        $repository = new ConfigRepository([
            'database' => [
                'default' => 'analytics',
                'connections' => [
                    'analytics' => [
                        'driver' => 'pgsql',
                        'host' => '127.0.0.1',
                        'port' => 5432,
                        'database' => 'analytics',
                    ],
                ],
                'telemetry' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $registry = new ConfigSchemaRegistry();
        $registry->register(DatabaseConfigSchema::build());

        $result = $repository->validateRegistered('database', $registry);

        self::assertTrue($result->isValid());
        self::assertSame('analytics', $result->data()['default']);
        self::assertSame('pgsql', $result->data()['connections']['analytics']['driver']);

        $repository->set('database', $result->data());

        $provider = new FrameworkDatabaseConfigurationProvider($repository);
        $configuration = $provider->configuration();

        self::assertSame('analytics', $configuration->defaultConnectionName);
        self::assertSame('pgsql', $configuration->connection('analytics')['driver'] ?? null);
        self::assertTrue($configuration->telemetryOption('enabled', false));
    }

    public function test_schema_root_must_be_map(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConfigSchema('invalid', ConfigNode::string());
    }
}
