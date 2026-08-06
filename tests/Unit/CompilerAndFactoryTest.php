<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\ArtifactStore;
use Quantum\Compilation\BuildManifest;
use Quantum\Compilation\CompiledControllerFactory;
use Quantum\Compilation\CompiledInvocationPlan;
use Quantum\Compilation\CompilationResult;
use Quantum\Compilation\Compiler;
use Quantum\Compilation\ControllerArtifact;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ResolvedController;
use VoltStack\Framework\Application;

final class CompilerAndFactoryTest extends TestCase
{
    private string $basePath;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-compiler-factory-' . uniqid('', true);
        $this->storageRoot = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'controllers';
        mkdir($this->storageRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_compiler_version_and_artifact_key_determinism(): void
    {
        $compiler = new Compiler();

        self::assertNotEmpty($compiler->version());
        self::assertSame(Compiler::VERSION, $compiler->version());

        $keyA = $compiler->makeArtifactKey(FooStub::class, 'bar');
        $keyB = $compiler->makeArtifactKey(FooStub::class, 'bar');
        $keyC = $compiler->makeArtifactKey(FooStub::class, 'baz');

        self::assertSame($keyA, $keyB, 'Same class+method must produce deterministic artifact key.');
        self::assertNotSame($keyA, $keyC, 'Different method must produce different artifact key.');
    }

    public function test_controller_definition_normalization_action_kept(): void
    {
        $byStringAt = new ControllerDefinition(FooStub::class . '@bar');
        self::assertSame(FooStub::class . '@bar', $byStringAt->action());

        $invokable = new ControllerDefinition(InvokableStub::class);
        self::assertSame(InvokableStub::class, $invokable->action());
    }

    public function test_factory_worker_cache_implements_lru_cap(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 16);

        for ($i = 0; $i < 40; $i++) {
            $artifact = new ControllerArtifact(
                key: 'key-' . $i,
                class: FooStub::class,
                method: 'bar',
                buildId: 'build-x',
                compiledAt: time(),
                sourceHash: 'hash-' . $i,
                compilerVersion: Compiler::VERSION,
                artifactPath: '/tmp/stub-' . $i . '.php',
            );
            $factory->workerCachePut($artifact);
        }

        $cleared = $factory->workerCacheClear();
        self::assertLessThanOrEqual(16, $cleared, 'Worker cache (min 16) must cap via LRU eviction, clear <= 16.');
    }

    public function test_factory_is_fresh_detects_source_changes(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 128);

        $staleArtifact = new ControllerArtifact(
            key: 'fake123',
            class: FooStub::class,
            method: 'bar',
            buildId: 'stale-build',
            compiledAt: time() - 10,
            sourceHash: hash('sha256', 'not-matching-source-fingerprint'),
            compilerVersion: Compiler::VERSION,
            artifactPath: '/tmp/fake.php',
        );

        self::assertFalse($factory->isFresh($staleArtifact), 'Fingerprint mismatch must mark artifact stale.');
    }

    public function test_factory_make_key_matches_compiler_key(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 128);

        $compiler = new Compiler();
        $definition = new ControllerDefinition(FooStub::class . '@bar');

        $definitionAction = $definition->action();
        $normalizedClass = str_contains($definitionAction, '@') ? explode('@', $definitionAction, 2)[0] : $definitionAction;
        $normalizedMethod = str_contains($definitionAction, '@') ? explode('@', $definitionAction, 2)[1] : '__invoke';

        self::assertSame(
            $compiler->makeArtifactKey($normalizedClass, $normalizedMethod),
            $factory->makeKey($definition),
            'Factory makeKey must match Compiler makeArtifactKey.',
        );
    }

    public function test_factory_plan_hydrates_from_artifact_metadata(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 128);

        $interceptors = [
            ['id' => 'auth-1', 'class' => 'App\\Interceptors\\AuthInterceptor', 'priority' => 100, 'phase' => 'pre', 'arguments' => []],
            ['id' => 'log-1', 'class' => 'App\\Interceptors\\LogInterceptor', 'priority' => 50, 'phase' => 'pre', 'arguments' => []],
        ];
        $parameterOrder = ['name', 'count'];
        $parameterTypeMap = ['name' => 'string', 'count' => 'int'];

        $artifact = new ControllerArtifact(
            key: 'plan-test-key',
            class: FooStub::class,
            method: 'bar',
            buildId: 'build-plan',
            compiledAt: time(),
            sourceHash: 'abc123',
            compilerVersion: Compiler::VERSION,
            artifactPath: '/tmp/plan.php',
            interceptorDefinitions: [
                ['id' => 'auth-1', 'class' => 'App\\Interceptors\\AuthInterceptor', 'priority' => 100],
                ['id' => 'log-1', 'class' => 'App\\Interceptors\\LogInterceptor', 'priority' => 50],
            ],
            parameterAliases: ['limit' => 'count'],
            runtimeMetadata: [
                'parameter_order' => $parameterOrder,
                'parameter_type_map' => $parameterTypeMap,
            ],
        );

        $plan = $factory->plan($artifact);

        self::assertInstanceOf(CompiledInvocationPlan::class, $plan);
        self::assertSame(FooStub::class, $plan->class);
        self::assertSame('bar', $plan->method);
        self::assertSame($interceptors, $plan->interceptors);
        self::assertSame($parameterOrder, $plan->parameterOrder);
        self::assertSame($parameterTypeMap, $plan->parameterTypeMap);
    }

    public function test_factory_load_reads_active_build_and_warms_worker_cache(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');
        $factory = new CompiledControllerFactory($store, 128);

        $build = $store->createBuild();
        $definition = new ControllerDefinition(FooStub::class . '@bar');
        $key = $factory->makeKey($definition);

        $reflection = new \ReflectionClass(FooStub::class);
        $filename = $reflection->getFileName();
        $mtime = is_string($filename) && is_file($filename) ? filemtime($filename) : 0;
        $size = is_string($filename) && is_file($filename) ? filesize($filename) : 0;
        $realSourceHash = hash('sha256', FooStub::class . '::bar'
            . '|file:' . (string) $filename
            . '|mtime:' . ($mtime ?: 0)
            . '|size:' . ($size ?: 0)
            . '|compiler:' . Compiler::VERSION);

        $phpCode = $this->buildPhpArtifactWithSource($key, FooStub::class, 'bar', $realSourceHash);
        $result = new CompilationResult(
            definition: $definition,
            artifactKey: $key,
            class: FooStub::class,
            method: 'bar',
            sourceHash: $realSourceHash,
            compiledPhpCode: $phpCode,
            compilerVersion: Compiler::VERSION,
        );

        $store->write($result, $build->id);
        $store->activateBuild($build->id);

        self::assertFalse($factory->workerCacheHas($key), 'Worker cache must be empty before first load.');

        $loaded = $factory->load($key);
        self::assertNotNull($loaded, 'First load (from store, isFresh check) must return artifact.');
        self::assertSame(FooStub::class, $loaded->class);
        self::assertSame('bar', $loaded->method);
        self::assertTrue($factory->workerCacheHas($key), 'After load, artifact must be present in worker cache.');

        $secondLoad = $factory->load($key);
        self::assertNotNull($secondLoad, 'Second load must return cached artifact.');
    }

    public function test_factory_materialize_returns_resolved_controller_via_container(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 128);

        $artifact = new ControllerArtifact(
            key: 'materialize-key',
            class: FooStub::class,
            method: 'bar',
            buildId: 'build-x',
            compiledAt: time(),
            sourceHash: 'abc',
            compilerVersion: Compiler::VERSION,
            artifactPath: '/tmp/mat.php',
        );

        $app = new Application($this->basePath);
        $resolved = $factory->materialize($artifact, $app);

        self::assertInstanceOf(ResolvedController::class, $resolved);
        self::assertInstanceOf(FooStub::class, $resolved->instance());
        self::assertSame('bar', $resolved->method());
    }

    public function test_factory_pin_build_per_execution_blocks_cross_build_artifacts(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot);
        $factory = new CompiledControllerFactory($store, 128);

        $buildA = $store->createBuild();
        $buildB = $store->createBuild();
        $store->activateBuild($buildA->id);

        $key = $factory->makeKey(new ControllerDefinition(FooStub::class . '@bar'));
        $artifactB = new ControllerArtifact(
            key: $key,
            class: FooStub::class,
            method: 'bar',
            buildId: $buildB->id,
            compiledAt: time(),
            sourceHash: 'hashB',
            compilerVersion: Compiler::VERSION,
            artifactPath: '/tmp/b.php',
        );
        $factory->workerCachePut($artifactB);

        $factory->beginPinnedExecution();
        self::assertSame($buildA->id, $factory->currentPinnedBuildId());

        $loadedWhilePinned = $factory->load($key);
        self::assertNull($loadedWhilePinned, 'Pinned to build A must reject artifact with buildId B from cache.');

        $factory->endPinnedExecution();
        self::assertNull($factory->currentPinnedBuildId());
    }

    private function buildPhpArtifact(string $key, string $class, string $method): string
    {
        return $this->buildPhpArtifactWithSource($key, $class, $method, hash('sha256', 'source-fingerprint'));
    }

    private function buildPhpArtifactWithSource(string $key, string $class, string $method, string $sourceHash): string
    {
        $now = date('c');
        $compilerVersion = Compiler::VERSION;
        $checksum = hash('sha256', $class . '::' . $method . '|' . $sourceHash . '|' . $compilerVersion);
        $schema = [
            'schema' => 1,
            'generated_at' => $now,
            'compiler_version' => $compilerVersion,
            'artifact_key' => $key,
            'class' => $class,
            'method' => $method,
            'interceptor_metadata' => [],
            'parameter_info' => (object)[],
            'parameter_aliases' => (object)[],
            'runtime_metadata' => (object)[],
            'source_hash' => $sourceHash,
            'checksum' => $checksum,
        ];

        return "<?php\n// AUTO-GENERATED: VoltStack Controller Compilation artifact\n// do not edit by hand\n\nreturn " . var_export($schema, true) . ";\n";
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $subPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_link($subPath) || is_file($subPath)) {
                @unlink($subPath);
            } else {
                $this->deleteDirectory($subPath);
            }
        }

        @rmdir($path);
    }
}

final class FooStub
{
    public function bar(string $name = 'world', int $count = 1): string
    {
        return 'hi ' . $name;
    }
}

final class InvokableStub
{
    public function __invoke(): string
    {
        return 'invoke';
    }
}