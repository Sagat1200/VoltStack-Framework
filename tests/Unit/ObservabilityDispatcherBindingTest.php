<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Engine\InMemoryControllerEventDispatcher;
use Quantum\Controllers\Observability\Engine\JsonLineControllerEventDispatcher;
use Quantum\Controllers\Observability\Engine\NullControllerEventDispatcher;
use VoltStack\Framework\Application;

final class ObservabilityDispatcherBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-observability-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_in_memory_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_observability.dispatcher', 'in_memory');

        $dispatcher = $app->make(ControllerEventDispatcherInterface::class);

        self::assertInstanceOf(InMemoryControllerEventDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_jsonl_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_observability.dispatcher', 'jsonl');
        $app->make(ConfigRepository::class)->set('controller_observability.jsonl_path', $this->basePath . DIRECTORY_SEPARATOR . 'events.jsonl');

        $dispatcher = $app->make(ControllerEventDispatcherInterface::class);

        self::assertInstanceOf(JsonLineControllerEventDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_null_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_observability.dispatcher', 'null');

        $dispatcher = $app->make(ControllerEventDispatcherInterface::class);

        self::assertInstanceOf(NullControllerEventDispatcher::class, $dispatcher);
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

