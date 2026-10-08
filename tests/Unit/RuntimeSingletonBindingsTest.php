<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\RuntimeManagerServer;

final class RuntimeSingletonBindingsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-singletons-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_runtime_singleton_bindings_are_stable_across_scope_changes_and_root_flushes(): void
    {
        $app = new Application($this->basePath);

        $workerFactory = $app->make(WorkerFactoryInterface::class);
        $runtimeManager = $app->make(RuntimeManagerServer::class);

        $app->enterScope('request');

        self::assertSame($workerFactory, $app->make(WorkerFactoryInterface::class));
        self::assertSame($runtimeManager, $app->make(RuntimeManagerServer::class));

        $app->leaveScope();
        $app->flushScope();

        self::assertSame($workerFactory, $app->make(WorkerFactoryInterface::class));
        self::assertSame($runtimeManager, $app->make(RuntimeManagerServer::class));
    }

    public function test_runtime_manager_server_preserves_registered_adapters_across_scope_changes(): void
    {
        $app = new Application($this->basePath);
        $manager = $app->make(RuntimeManagerServer::class);

        $manager->registerAdapter(new RuntimeSingletonTestAdapter());

        $app->enterScope('request');
        $app->leaveScope();
        $app->flushScope();

        $reused = $app->make(RuntimeManagerServer::class);

        self::assertSame($manager, $reused);
        self::assertSame(['frankenphp', 'sapi', 'test-singleton'], $reused->drivers());
        self::assertSame('test-singleton', $reused->adapter('test-singleton')->id());
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

final class RuntimeSingletonTestAdapter implements RuntimeAdapterInterface
{
    public function id(): string
    {
        return 'test-singleton';
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            persistent: true,
            concurrent: false,
            gracefulReload: false,
        );
    }

    public function run(
        \Quantum\Bootstrap\ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        return 0;
    }
}
