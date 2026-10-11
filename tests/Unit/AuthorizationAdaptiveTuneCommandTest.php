<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationAdaptiveTuneCommand;
use Quantum\Authorization\Core\Stages\AdaptiveAccessRuntimeConfig;
use Quantum\Config\ConfigRepository;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationAdaptiveTuneCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_tune_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_tune_test_app'] = null;
        unset($GLOBALS['__volt_authz_tune_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationAdaptiveTuneCommand($this->tempBasePath);

        self::assertSame('authz:adaptive:tune', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:adaptive:tune', $command->aliases());
        self::assertContains('authz:tune-adaptive', $command->aliases());
    }

    public function test_inspect_mode_emits_json_with_resolved_config(): void
    {
        $app = $this->buildApp(tuneEnabled: false);
        $app->make(ConfigRepository::class)->set('authorization.adaptive_access', [
            'enabled' => true,
            'step_up_threshold' => 40,
            'deny_threshold' => 80,
            'allow_threshold' => 10,
            'policies' => [
                'operation:posts.publish' => ['deny_threshold' => 30],
                'tenant:acme' => ['step_up_threshold' => 50],
            ],
        ]);
        $this->writeBootstrap($app);

        $command = new AuthorizationAdaptiveTuneCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:adaptive:tune',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        $decoded = json_decode($output->stdout(), true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['enabled']);
        self::assertSame(40, $decoded['step_up_threshold']);
        self::assertSame(80, $decoded['deny_threshold']);
        self::assertSame(10, $decoded['allow_threshold']);
        self::assertSame(2, $decoded['policies_count']);
        self::assertContains('operation:posts.publish', $decoded['policies']);
        self::assertContains('tenant:acme', $decoded['policies']);
    }

    public function test_apply_requires_tune_enabled(): void
    {
        $app = $this->buildApp(tuneEnabled: false);
        $this->writeBootstrap($app);

        $command = new AuthorizationAdaptiveTuneCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:adaptive:tune',
            '--apply',
            '--deny-threshold=90',
        ]), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('adaptive.tune.enabled', $output->stderr());
    }

    public function test_apply_updates_runtime_config_when_enabled(): void
    {
        $app = $this->buildApp(tuneEnabled: true);
        $runtime = new AdaptiveAccessRuntimeConfig();
        $app->instance(AdaptiveAccessRuntimeConfig::class, $runtime);
        $this->writeBootstrap($app);

        $command = new AuthorizationAdaptiveTuneCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:adaptive:tune',
            '--apply',
            '--deny-threshold=90',
            '--allow-threshold=5',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame(90, $runtime->denyThreshold());
        self::assertSame(5, $runtime->allowThreshold());
        self::assertNull($runtime->stepUpThreshold());
    }

    private function buildApp(bool $tuneEnabled): Application
    {
        $app = new Application($this->tempBasePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.adaptive.tune.enabled', $tuneEnabled);
        $config->set('authorization.adaptive_access', [
            'enabled' => false,
            'step_up_threshold' => null,
            'deny_threshold' => null,
            'allow_threshold' => null,
            'policies' => [],
        ]);

        return $app;
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_tune_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_tune_test_app' . "'" . '];' . PHP_EOL,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
