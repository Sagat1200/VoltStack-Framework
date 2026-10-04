<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Enums\WorkerDisposition;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\WorkerLifecycle;

final class WorkerLifecycleTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-worker-lifecycle-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_persists_across_scope_flushes(): void
    {
        $app = new Application($this->basePath);
        $lifecycle = $app->make(WorkerLifecycle::class);

        self::assertFalse($lifecycle->shouldTerminate());

        $lifecycle->request(WorkerDisposition::Terminate);

        $app->flushScope();

        self::assertTrue($app->make(WorkerLifecycle::class)->shouldTerminate());
        self::assertSame('terminate', $app->make(WorkerLifecycle::class)->lastDisposition());
    }

    public function test_it_is_reused_across_request_scopes_as_a_worker_owned_binding(): void
    {
        $app = new Application($this->basePath);
        $rootLifecycle = $app->make(WorkerLifecycle::class);

        $app->enterScope('request');
        $requestLifecycle = $app->make(WorkerLifecycle::class);

        self::assertSame($rootLifecycle, $requestLifecycle);

        $app->enterScope('tenant');
        self::assertSame($rootLifecycle, $app->make(WorkerLifecycle::class));
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
