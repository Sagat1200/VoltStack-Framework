<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\ArtifactStore;
use Quantum\Compilation\Build;
use Quantum\Compilation\BuildManifest;
use Quantum\Compilation\CompilationResult;
use Quantum\Compilation\Compiler;
use Quantum\Compilation\ControllerArtifact;
use Quantum\Compilation\Exceptions\BuildActivationException;
use Quantum\Controllers\ControllerDefinition;

final class BuildAndArtifactTest extends TestCase
{
    private string $basePath;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-compilation-build-' . uniqid('', true);
        $this->storageRoot = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'controllers';
        mkdir($this->storageRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_build_manifest_creates_and_activates(): void
    {
        $manifest = new BuildManifest($this->storageRoot);

        $buildA = $manifest->create();
        self::assertNotEmpty($buildA->id);
        self::assertSame(0, $buildA->controllerCount);
        self::assertFalse($buildA->active);
        self::assertSame('', $buildA->previousBuildId, 'First build must have empty previousBuildId.');

        $buildB = $manifest->create();
        self::assertNotSame($buildA->id, $buildB->id);
        self::assertSame('', $buildB->previousBuildId, 'previousBuildId is populated only after setCurrent/activation.');

        $activatedA = $manifest->setCurrent($buildA->id);
        self::assertTrue($activatedA->active);
        self::assertSame($buildA->id, $activatedA->id);

        $current = $manifest->current();
        self::assertNotNull($current);
        self::assertSame($buildA->id, $current->id);
        self::assertTrue($current->active);

        $all = $manifest->all();
        self::assertCount(2, $all);
    }

    public function test_build_manifest_rollback_returns_previous(): void
    {
        $manifest = new BuildManifest($this->storageRoot);

        $first = $manifest->create();
        $second = $manifest->create();
        $third = $manifest->create();

        $manifest->setCurrent($first->id);
        $manifest->setCurrent($second->id);
        $manifest->setCurrent($third->id);
        self::assertSame($third->id, $manifest->current()?->id);

        $rolled = $manifest->rollback();
        self::assertNotNull($rolled);
        self::assertSame($second->id, $rolled->id, 'Rollback must re-activate previous build.');
    }

    public function test_build_manifest_prune_retains_active_and_recent(): void
    {
        $manifest = new BuildManifest($this->storageRoot);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $build = $manifest->create();
            $ids[] = $build->id;
            usleep(2000);
        }

        $last = end($ids);
        $manifest->setCurrent($last);

        $retained = $manifest->prune(2);
        $retainedIds = array_map(static fn(Build $b) => $b->id, $retained);

        self::assertContains($last, $retainedIds, 'Active build must always be retained.');
        self::assertLessThanOrEqual(2, count($retainedIds), 'Prune N=2 must keep at most 2 builds total.');
    }

    public function test_artifact_store_paths_and_initial_state(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        self::assertSame($this->storageRoot, $store->rootPath());
        self::assertSame($this->storageRoot . DIRECTORY_SEPARATOR . 'builds', $store->buildsPath());
        self::assertSame($this->storageRoot . DIRECTORY_SEPARATOR . 'current', $store->currentPath());
        self::assertNull($store->currentBuild());
    }

    public function test_artifact_store_write_validate_read_and_exists(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        $build = $store->createBuild();
        self::assertInstanceOf(Build::class, $build);
        self::assertNotEmpty($build->id);

        $definition = new ControllerDefinition(SampleArtifactController::class . '@index');
        $key = hash('sha256', 'SampleController@index|' . Compiler::VERSION);

        $phpCode = $this->buildPhpArtifact($key, SampleArtifactController::class, 'index');
        $result = new CompilationResult(
            definition: $definition,
            artifactKey: $key,
            class: SampleArtifactController::class,
            method: 'index',
            sourceHash: hash('sha256', 'source-fingerprint'),
            compiledPhpCode: $phpCode,
            compilerVersion: Compiler::VERSION,
        );

        $artifact = $store->write($result, $build->id);
        self::assertSame($key, $artifact->key);
        self::assertSame(SampleArtifactController::class, $artifact->class);
        self::assertSame('index', $artifact->method);
        self::assertSame($build->id, $artifact->buildId);
        self::assertFileExists($artifact->artifactPath);

        self::assertTrue($store->validate($artifact), 'Artifact post-write checksum validation must pass.');

        $store->activateBuild($build->id);
        self::assertTrue($store->exists($artifact->key), 'Artifact must be findable via key after build activation.');

        $readBack = $store->read($artifact->key);
        self::assertNotNull($readBack);
        self::assertSame($artifact->class, $readBack->class);
        self::assertSame($artifact->method, $readBack->method);
        self::assertSame($artifact->buildId, $readBack->buildId);
        self::assertSame($artifact->key, $readBack->key);
    }

    public function test_artifact_store_create_list_and_reuse_previous_builds(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        for ($i = 0; $i < 3; $i++) {
            $store->createBuild();
        }

        $allBuilds = $manifest->all();
        self::assertCount(3, $allBuilds);
    }

    public function test_artifact_store_activation_fails_for_missing_build(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        $this->expectException(BuildActivationException::class);
        $store->activateBuild('nonexistent-build-id');
    }

    public function test_artifact_store_rollback_and_clear_builds(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        $first = $store->createBuild();
        $store->activateBuild($first->id);

        $second = $store->createBuild();
        $store->activateBuild($second->id);

        self::assertSame($second->id, $store->currentBuild()?->id);

        $rolled = $store->rollback();
        self::assertNotNull($rolled);
        self::assertSame($first->id, $rolled->id, 'Rollback must reactivate previous build.');

        $clearedCount = $store->clearBuilds();
        self::assertGreaterThanOrEqual(2, $clearedCount);

        $freshManifest = new BuildManifest($this->storageRoot);
        self::assertNull($freshManifest->current(), 'After clearBuilds manifest must have no current build.');
    }

    public function test_artifact_store_prune_stale_builds_returns_pruned(): void
    {
        $manifest = new BuildManifest($this->storageRoot);
        $store = new ArtifactStore($manifest, $this->storageRoot, 'php');

        for ($i = 0; $i < 5; $i++) {
            $build = $store->createBuild();
            usleep(2000);
        }

        $active = $store->createBuild();
        $store->activateBuild($active->id);

        $pruned = $store->pruneStaleBuilds(2);
        self::assertGreaterThanOrEqual(3, $pruned, '5 previous + 1 active = 6 total. Prune retain=2 must remove >=3.');

        $remaining = $manifest->all();
        self::assertLessThanOrEqual(2, count($remaining), 'After prune we must have <= 2 builds.');
        $current = $manifest->current();
        self::assertNotNull($current);
        self::assertSame($active->id, $current->id, 'Active build must never be pruned.');
    }

    private function buildPhpArtifact(string $key, string $class, string $method): string
    {
        $now = date('c');
        $compilerVersion = Compiler::VERSION;
        $sourceHash = hash('sha256', 'source-fingerprint');
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

final class SampleArtifactController
{
    public function index(): string
    {
        return 'ok';
    }
}