<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\ExceptionHandlingResult;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\WorkerLifecycle;

final class PlatformExceptionHandlerWorkerDispositionTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-platform-exception-disposition-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_records_worker_disposition_from_quantum_handler(): void
    {
        $app = new Application($this->basePath);

        $app->singleton(ExceptionHandlerInterface::class, fn() => new class implements ExceptionHandlerInterface {
            public function handle(Throwable $throwable, ExceptionHandlingContext $context): ExceptionHandlingResult
            {
                return new ExceptionHandlingResult(
                    response: new Response('', 500, ['Content-Type' => 'text/html; charset=UTF-8']),
                    workerDisposition: WorkerDisposition::Terminate,
                    emissionStarted: false,
                );
            }
        });

        $handler = $app->make(\VoltStack\Framework\Exceptions\ExceptionHandler::class);
        $handler->render(Request::create('/boom'), new \Exception('boom'));

        self::assertTrue($app->make(WorkerLifecycle::class)->shouldTerminate());
        self::assertSame('terminate', $app->make(WorkerLifecycle::class)->lastDisposition());
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