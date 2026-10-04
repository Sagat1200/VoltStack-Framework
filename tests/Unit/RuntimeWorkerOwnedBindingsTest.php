<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;
use VoltStack\Runtime\RequestRunner;
use VoltStack\Runtime\Reset\ResetManager;

final class RuntimeWorkerOwnedBindingsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-worker-owned-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_runtime_worker_owned_bindings_are_reused_across_request_scopes_and_root_flushes(): void
    {
        $app = new Application($this->basePath);

        $scopeManager = $app->make(ScopeManager::class);
        $resetManager = $app->make(ResetManager::class);
        $requestRunner = $app->make(RequestRunner::class);

        $app->enterScope('request');

        self::assertSame($scopeManager, $app->make(ScopeManager::class));
        self::assertSame($resetManager, $app->make(ResetManager::class));
        self::assertSame($requestRunner, $app->make(RequestRunner::class));

        $app->leaveScope();
        $app->flushScope();

        self::assertSame($scopeManager, $app->make(ScopeManager::class));
        self::assertSame($resetManager, $app->make(ResetManager::class));
        self::assertSame($requestRunner, $app->make(RequestRunner::class));
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
