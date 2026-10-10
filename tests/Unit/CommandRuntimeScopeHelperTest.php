<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

final class CommandRuntimeScopeHelperTest extends TestCase
{
    public function test_command_runtime_helper_runs_inside_command_scope_and_restores_root(): void
    {
        $app = new Application(sys_get_temp_dir());
        $command = new TestRuntimeAwareCommand($app);

        $result = $command->runHelper(function (Application $app): array {
            $context = RuntimeContext::current();

            self::assertNotNull($context);
            self::assertSame('command', $app->currentScopeKind());

            return [
                'request_uri' => $context->request()->uri(),
                'scope_kind' => $context->get('runtime.scope_kind'),
                'channel' => $context->get('runtime.channel'),
                'unit_name' => $context->get('runtime.unit_name'),
            ];
        });

        self::assertSame('/_cli/command/test-runtime-aware-command', $result['request_uri']);
        self::assertSame('command', $result['scope_kind']);
        self::assertSame('cli', $result['channel']);
        self::assertSame('test:runtime-aware-command', $result['unit_name']);
        self::assertSame('root', $app->currentScopeKind());
        self::assertFalse($app->hasActiveScope());
        self::assertNull(RuntimeContext::current());
    }

    public function test_command_runtime_helper_closes_scope_when_callback_fails(): void
    {
        $app = new Application(sys_get_temp_dir());
        $command = new TestRuntimeAwareCommand($app);

        try {
            $command->runHelper(function (): never {
                throw new \RuntimeException('helper failed');
            });
            self::fail('The helper should rethrow callback exceptions.');
        } catch (\RuntimeException $exception) {
            self::assertSame('helper failed', $exception->getMessage());
        }

        self::assertSame('root', $app->currentScopeKind());
        self::assertFalse($app->hasActiveScope());
        self::assertNull(RuntimeContext::current());
    }
}

final class TestRuntimeAwareCommand extends Command
{
    public function __construct(private readonly Application $app)
    {
        parent::__construct(sys_get_temp_dir());
    }

    public function name(): string
    {
        return 'test:runtime-aware-command';
    }

    public function description(): string
    {
        return 'Test command for runtime scope helper coverage.';
    }

    public function handle(Input $input, Output $output): int
    {
        return 0;
    }

    public function runHelper(callable $callback): mixed
    {
        return $this->runInCommandRuntime($callback);
    }

    protected function bootstrapApplication(bool $requirePublishedConfig = false): Application
    {
        return $this->app;
    }
}
