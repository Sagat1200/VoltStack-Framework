<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Schema\Builtin\CacheConfigSchema;
use Quantum\Config\Schema\Builtin\DatabaseConfigSchema;
use Quantum\Config\Schema\Builtin\ExceptionsConfigSchema;
use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;
use Quantum\Config\Schema\ConfigSchemaRegistry;
use Quantum\Config\Validation\ConfigValidator;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;

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

    public function test_exceptions_schema_applies_defaults_and_accepts_current_shape(): void
    {
        $schema = ExceptionsConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'environment' => 'development',
            'reporting' => [
                'sample_rate' => 0.5,
                'reporters' => ['exceptions.log'],
            ],
            'rules' => [
                [
                    'id' => 'runtime',
                    'exceptionType' => \RuntimeException::class,
                    'serviceId' => 'exceptions.rule.runtime',
                ],
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame(1, $result->data()['schema_version']);
        self::assertFalse($result->data()['debug']);
        self::assertSame(0.5, $result->data()['reporting']['sample_rate']);
        self::assertSame('problem_json', $result->data()['rendering']['api_format']);
        self::assertSame([1], $result->data()['rendering']['spa_versions']);
        self::assertSame([], $result->data()['rules'][0]['predicates']);
        self::assertFalse($result->data()['rules'][0]['exclusive']);
    }

    public function test_exceptions_schema_rejects_unknown_keys_and_string_debug(): void
    {
        $schema = ExceptionsConfigSchema::build();
        $validator = new ConfigValidator();

        $result = $validator->validate($schema, [
            'debug' => 'true',
            'unexpected' => true,
            'rendering' => [
                'unknown' => 'value',
            ],
        ]);

        self::assertFalse($result->isValid());
        self::assertCount(3, $result->violations());
        self::assertSame('exceptions.debug', $result->violations()[0]->path());
        self::assertSame('exceptions.rendering.unknown', $result->violations()[1]->path());
        self::assertSame('exceptions.unexpected', $result->violations()[2]->path());
    }

    public function test_repository_can_validate_registered_exceptions_schema_and_compile_plan(): void
    {
        $repository = new ConfigRepository([
            'exceptions' => [
                'environment' => 'development',
                'reporting' => [
                    'reporters' => ['exceptions.telemetry', 'exceptions.log'],
                    'ignore_codes' => ['validation.failed', 'authorization.denied'],
                ],
                'rendering' => [
                    'spa_versions' => [1],
                ],
                'rules' => [
                    [
                        'id' => 'runtime',
                        'exceptionType' => \RuntimeException::class,
                        'priority' => 10,
                        'serviceId' => 'exceptions.rule.runtime',
                        'exclusive' => false,
                        'predicates' => ['predicate.beta', 'predicate.alpha'],
                    ],
                ],
            ],
        ]);

        $registry = new ConfigSchemaRegistry();
        $registry->register(ExceptionsConfigSchema::build());

        $result = $repository->validateRegistered('exceptions', $registry);

        self::assertTrue($result->isValid());
        self::assertSame('development', $result->data()['environment']);
        self::assertSame(1.0, $result->data()['reporting']['sample_rate']);

        $repository->set('exceptions', $result->data());

        $plan = (new ExceptionPlanCompiler())->compile($result->data());

        self::assertSame('development', $plan->environment());
        self::assertSame(['exceptions.log', 'exceptions.telemetry'], $plan->config()['reporting']['reporters']);
        self::assertSame(['authorization.denied', 'validation.failed'], $plan->config()['reporting']['ignore_codes']);
        self::assertSame(['predicate.alpha', 'predicate.beta'], $plan->config()['rules'][0]['predicates']);
    }

    public function test_schema_root_must_be_map(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConfigSchema('invalid', ConfigNode::string());
    }
}
