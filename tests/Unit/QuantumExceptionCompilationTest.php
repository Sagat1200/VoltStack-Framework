<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Exceptions\Catalog\SemanticErrorCatalog;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Core\ExceptionDescriptorFactory;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;
use RuntimeException;
use VoltStack\Framework\Application;

final class QuantumExceptionCompilationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-exception-compilation-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_compiler_rejects_unknown_configuration_keys(): void
    {
        $compiler = new ExceptionPlanCompiler();

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('Unknown exception configuration key [exceptions.unexpected_key].');

        $compiler->compile([
            'unexpected_key' => true,
        ]);
    }

    public function test_compiler_rejects_debug_in_production(): void
    {
        $compiler = new ExceptionPlanCompiler();

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('exceptions.debug');

        $compiler->compile([
            'environment' => 'production',
            'debug' => true,
        ]);
    }

    public function test_compiler_generates_deterministic_fingerprint_for_equivalent_lists(): void
    {
        $compiler = new ExceptionPlanCompiler();

        $first = $compiler->compile([
            'reporting' => [
                'reporters' => ['exceptions.telemetry', 'exceptions.log'],
                'ignore_codes' => ['authorization.denied', 'validation.failed'],
            ],
            'rules' => [
                [
                    'id' => 'runtime',
                    'exceptionType' => RuntimeException::class,
                    'priority' => 10,
                    'serviceId' => 'exceptions.rule.runtime',
                    'exclusive' => false,
                    'predicates' => ['predicate.beta', 'predicate.alpha'],
                ],
            ],
        ]);

        $second = $compiler->compile([
            'reporting' => [
                'reporters' => ['exceptions.log', 'exceptions.telemetry'],
                'ignore_codes' => ['validation.failed', 'authorization.denied'],
            ],
            'rules' => [
                [
                    'id' => 'runtime',
                    'exceptionType' => RuntimeException::class,
                    'priority' => 10,
                    'serviceId' => 'exceptions.rule.runtime',
                    'exclusive' => false,
                    'predicates' => ['predicate.alpha', 'predicate.beta'],
                ],
            ],
        ]);

        self::assertSame($first->fingerprint(), $second->fingerprint());
        self::assertSame(['exceptions.log', 'exceptions.telemetry'], $first->config()['reporting']['reporters']);
        self::assertSame(['authorization.denied', 'validation.failed'], $first->config()['reporting']['ignore_codes']);
        self::assertSame(['predicate.alpha', 'predicate.beta'], $first->config()['rules'][0]['predicates']);
    }

    public function test_plan_store_persists_and_rehydrates_compiled_plan(): void
    {
        $compiler = new ExceptionPlanCompiler();
        $store = new ExceptionPlanStore($this->basePath . DIRECTORY_SEPARATOR . 'storage');
        $plan = $compiler->compile([
            'environment' => 'development',
        ]);

        $path = $store->persist($plan);
        $loaded = $store->load();

        self::assertFileExists($path);
        self::assertInstanceOf(ExceptionCompilationPlan::class, $loaded);
        self::assertSame($plan->fingerprint(), $loaded?->fingerprint());
        self::assertSame($plan->config(), $loaded?->config());
    }

    public function test_application_exposes_compiled_exception_plan_binding(): void
    {
        $app = new Application($this->basePath);

        $plan = $app->make(ExceptionCompilationPlan::class);

        self::assertInstanceOf(ExceptionCompilationPlan::class, $plan);
        self::assertSame('production', $plan->environment());
        self::assertSame('frankenphp', $plan->runtime());
        self::assertFalse($plan->debug());
        self::assertContains('exceptions.log', $plan->reporterIds());
    }

    public function test_application_requires_a_published_plan_in_production_once_exception_config_is_loaded(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('A published exception compilation plan is required in production');

        $app->make(ExceptionCompilationPlan::class);
    }

    public function test_application_uses_the_published_plan_in_production_when_available(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $compiler = new ExceptionPlanCompiler();
        $publishedPlan = $compiler->compile([
            'environment' => 'production',
            'debug' => false,
            'runtime' => 'sapi',
            'compilation' => [
                'required_in_production' => true,
            ],
        ]);

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $app->make(ExceptionPlanStore::class)->persist($publishedPlan);

        $resolved = $app->make(ExceptionCompilationPlan::class);

        self::assertSame($publishedPlan->fingerprint(), $resolved->fingerprint());
        self::assertSame($publishedPlan->config(), $resolved->config());
    }

    public function test_application_rejects_corrupted_published_plan_in_production(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $this->writePublishedPlanArtifact($app, <<<'PHP'
<?php

return 'corrupted';
PHP);

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('invalid or corrupted');

        $app->make(ExceptionCompilationPlan::class);
    }

    public function test_application_rejects_published_plan_with_incompatible_schema_version(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $compiler = new ExceptionPlanCompiler();
        $payload = $compiler->compile([
            'environment' => 'production',
            'debug' => false,
            'runtime' => 'sapi',
            'compilation' => [
                'required_in_production' => true,
            ],
        ])->toArray();
        $payload['schema_version'] = 2;

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $this->writePublishedPlanArtifact($app, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('artifact payload is invalid');

        $app->make(ExceptionCompilationPlan::class);
    }

    public function test_application_rejects_published_plan_with_incompatible_php_runtime_version(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $compiler = new ExceptionPlanCompiler();
        $payload = $compiler->compile([
            'environment' => 'production',
            'debug' => false,
            'runtime' => 'sapi',
            'compilation' => [
                'required_in_production' => true,
            ],
        ])->toArray();
        $payload['php_runtime_version'] = '0.0.0';

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $this->writePublishedPlanArtifact($app, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('PHP runtime version [0.0.0] is incompatible');

        $app->make(ExceptionCompilationPlan::class);
    }

    public function test_application_rejects_published_plan_with_incompatible_runtime_id(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'production',
    'debug' => false,
    'runtime' => 'sapi',
    'compilation' => [
        'required_in_production' => true,
    ],
];
PHP);

        $compiler = new ExceptionPlanCompiler();
        $payload = $compiler->compile([
            'environment' => 'production',
            'debug' => false,
            'runtime' => 'sapi',
            'compilation' => [
                'required_in_production' => true,
            ],
        ])->toArray();
        $payload['config']['runtime'] = 'frankenphp';

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');
        $this->writePublishedPlanArtifact($app, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $this->expectException(ExceptionCompilationException::class);
        $this->expectExceptionMessage('runtime [frankenphp] is incompatible with configured runtime [sapi]');

        $app->make(ExceptionCompilationPlan::class);
    }

    public function test_application_applies_rendering_configuration_to_transport_mapper_binding(): void
    {
        $this->writeConfig('exceptions', <<<'PHP'
<?php

return [
    'environment' => 'development',
    'debug' => false,
    'runtime' => 'sapi',
    'rendering' => [
        'api_format' => 'json',
        'browser_format' => 'json',
        'spa_versions' => [1],
        'cache_control' => 'no-store',
    ],
];
PHP);

        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $mapper = $app->make(TransportMapperInterface::class);
        $descriptor = $this->descriptor('validation.failed');

        $apiPlan = $mapper->map($descriptor, new TransportContext(
            kind: 'http',
            routeProfile: 'api',
        ));
        $browserPlan = $mapper->map($descriptor, new TransportContext(
            kind: 'http',
            routeProfile: 'browser',
        ));

        self::assertSame('http.json', $apiPlan->target);
        self::assertSame('http.json', $browserPlan->target);
        self::assertSame('no-store', $apiPlan->headers['Cache-Control'] ?? null);
    }

    private function writeConfig(string $name, string $contents): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        if (! is_dir($configPath) && ! mkdir($configPath, 0777, true) && ! is_dir($configPath)) {
            throw new RuntimeException(sprintf('Unable to create config directory [%s].', $configPath));
        }

        file_put_contents($configPath . DIRECTORY_SEPARATOR . $name . '.php', $contents);
    }

    private function writePublishedPlanArtifact(Application $app, string $contents): void
    {
        $path = $app->make(ExceptionPlanStore::class)->currentPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create published plan directory [%s].', $directory));
        }

        file_put_contents($path, $contents);
    }

    private function descriptor(string $code): \Quantum\Exceptions\Model\ExceptionDescriptor
    {
        $catalog = SemanticErrorCatalog::defaults();
        $entry = $catalog->require($code);

        return (new ExceptionDescriptorFactory())->create(
            occurrenceId: 'occ-compilation',
            failure: new FailureSnapshot(
                className: RuntimeException::class,
                internalMessage: 'Failure',
                origin: 'exception',
            ),
            semantic: new SemanticError(
                code: $entry->code,
                category: $entry->category,
                messageKey: $entry->messageKey,
                safeParameters: [],
                severity: $entry->severity,
                effect: $entry->effect,
                retryAdvice: $entry->retryAdvice,
            ),
            context: new ExceptionContext(scopeId: 'scope-compilation'),
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
