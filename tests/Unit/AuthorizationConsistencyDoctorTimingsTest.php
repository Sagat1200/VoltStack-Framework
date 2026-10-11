<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyDoctorCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationConsistencyDoctorTimingsTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_doctor_timings_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_doctor_timings_test_app'] = null;
        unset($GLOBALS['__volt_authz_doctor_timings_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_with_timings_emits_stage_timings(): void
    {
        $app = new Application($this->tempBasePath);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:doctor',
            '--with-timings',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        $decoded = json_decode($output->stdout(), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('stage_timings', $decoded);
        self::assertArrayHasKey('stages', $decoded['stage_timings']);
        self::assertArrayHasKey('total_duration_ms', $decoded['stage_timings']);
    }

    public function test_verbose_emits_tenant_overrides(): void
    {
        $app = new Application($this->tempBasePath);
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.adaptive_access.policies', [
            'tenant:acme' => [
                'step_up_threshold' => 50,
                'deny_threshold' => 90,
            ],
            'operation:posts.publish' => [
                'deny_threshold' => 30,
            ],
        ]);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:doctor',
            '--verbose',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        $decoded = json_decode($output->stdout(), true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('tenant_overrides', $decoded);
        self::assertArrayHasKey('tenant', $decoded['tenant_overrides']);
        self::assertArrayHasKey('operation', $decoded['tenant_overrides']);

        $tenantEntry = $decoded['tenant_overrides']['tenant'][0];
        self::assertSame('tenant:acme', $tenantEntry['key']);
        self::assertSame(50, $tenantEntry['thresholds']['step_up_threshold']);
    }

    public function test_without_flags_output_has_no_timings_or_overrides(): void
    {
        $app = new Application($this->tempBasePath);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:doctor',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        $decoded = json_decode($output->stdout(), true);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('stage_timings', $decoded);
        self::assertArrayNotHasKey('tenant_overrides', $decoded);
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_doctor_timings_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_doctor_timings_test_app' . "'" . '];' . PHP_EOL,
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
