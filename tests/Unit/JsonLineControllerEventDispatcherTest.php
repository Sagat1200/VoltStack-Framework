<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Observability\Engine\JsonLineControllerEventDispatcher;
use Quantum\Controllers\Observability\Events\ControllerEvent;

final class JsonLineControllerEventDispatcherTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-observability-jsonl-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_writes_a_sanitized_json_line(): void
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . 'events.jsonl';
        $dispatcher = new JsonLineControllerEventDispatcher($file, maxBytesPerLine: 4096);

        $dispatcher->dispatch(new ControllerEvent(
            name: 'controllers.execution.created',
            version: 1,
            executionId: 'abc',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            sequence: 1,
            payload: [
                'obj' => new \stdClass(),
                'big' => str_repeat('x', 5000),
            ],
        ));

        self::assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines);

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('controller_event', $decoded['type']);
        self::assertSame('controllers.execution.created', $decoded['name']);
        self::assertSame('abc', $decoded['executionId']);
        self::assertSame(1, $decoded['sequence']);

        self::assertArrayHasKey('payload', $decoded);

        $payload = $decoded['payload'];
        self::assertIsArray($payload);
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

