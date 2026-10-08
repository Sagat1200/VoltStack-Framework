<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\BootstrapReleaseCheckCommand;
use Quantum\Console\Commands\BootstrapStatusCommand;
use Quantum\Console\Commands\BootstrapBenchmarkCommand;
use Quantum\Console\Commands\CacheClearCommand;
use Quantum\Console\Commands\ConfigReleaseCheckCommand;
use Quantum\Console\Commands\ConfigStatusCommand;
use Quantum\Console\Commands\MakeActionCommand;
use Quantum\Console\Commands\MakeComponentCommand;
use Quantum\Console\Commands\MakeLayoutCommand;
use Quantum\Console\Commands\MakeControllerCommand;
use Quantum\Console\Commands\MakePageCommand;
use Quantum\Console\Commands\MakeViewCommand;
use Quantum\Console\Commands\RouteCacheCommand;
use Quantum\Console\Commands\RouteClearCommand;
use Quantum\Console\Commands\RouteListCommand;
use Quantum\Console\Commands\RuntimeBudgetCalibrateCommand;
use Quantum\Console\Commands\RuntimeSmokeCheckCommand;
use Quantum\Console\Commands\RuntimeStatusCommand;
use Quantum\Console\Commands\ServeCommand;
use Quantum\Console\Commands\ViewCacheCommand;
use Quantum\Console\Commands\ViewClearCommand;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;

final class ConsoleApplicationTest extends TestCase
{
    public function test_it_renders_general_help_when_no_command_is_provided(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('VoltStack Console', $output->stdout());
        self::assertStringContainsString('Usage: php volt <command> [options]', $output->stdout());
        self::assertStringContainsString('Use "php volt help <command>" for command details.', $output->stdout());
        self::assertStringContainsString('Development:', $output->stdout());
        self::assertStringContainsString('Routing:', $output->stdout());
        self::assertStringContainsString('Generators:', $output->stdout());
        self::assertStringContainsString('Cache:', $output->stdout());
        self::assertStringContainsString('Runtime:', $output->stdout());
        self::assertStringContainsString('serve', $output->stdout());
        self::assertStringContainsString('route:cache', $output->stdout());
        self::assertStringContainsString('route:clear', $output->stdout());
        self::assertStringContainsString('make:controller', $output->stdout());
        self::assertStringContainsString('make:layout', $output->stdout());
        self::assertStringContainsString('bootstrap:release-check', $output->stdout());
        self::assertStringContainsString('bootstrap:benchmark', $output->stdout());
        self::assertStringContainsString('bootstrap:status', $output->stdout());
        self::assertStringContainsString('config:release-check', $output->stdout());
        self::assertStringContainsString('config:status', $output->stdout());
        self::assertStringContainsString('runtime:budget-calibrate', $output->stdout());
        self::assertStringContainsString('runtime:smoke-check', $output->stdout());
        self::assertStringContainsString('runtime:status', $output->stdout());
        self::assertStringContainsString('[aliases: routes]', $output->stdout());
    }

    public function test_it_renders_command_help_via_help_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'cache:clear',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: cache:clear', $output->stdout());
        self::assertStringContainsString('Usage: php volt cache:clear [--data-only] [--compiled-only] [--verbose]', $output->stdout());
        self::assertStringContainsString('--data-only', $output->stdout());
        self::assertStringContainsString('--compiled-only', $output->stdout());
        self::assertStringContainsString('--verbose', $output->stdout());
    }

    public function test_it_renders_command_help_via_help_option(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'view:cache',
            '--help',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: view:cache', $output->stdout());
        self::assertStringContainsString('Usage: php volt view:cache [--verbose]', $output->stdout());
        self::assertStringContainsString('--verbose', $output->stdout());
    }

    public function test_it_renders_route_cache_help(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'route:cache',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: route:cache', $output->stdout());
        self::assertStringContainsString('Usage: php volt route:cache [--verbose] [--optimizer-only]', $output->stdout());
        self::assertStringContainsString('Aliases: routes:cache', $output->stdout());
    }

    public function test_it_renders_argument_help_for_make_commands(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'make:component',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: make:component', $output->stdout());
        self::assertStringContainsString('Usage: php volt make:component <name>', $output->stdout());
        self::assertStringContainsString('Arguments:', $output->stdout());
        self::assertStringContainsString('Admin/UserCard', $output->stdout());
    }

    public function test_it_renders_option_help_for_serve_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'serve',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Usage: php volt serve [--host=127.0.0.1] [--port=8000] [--dry-run]', $output->stdout());
        self::assertStringContainsString('--host=', $output->stdout());
        self::assertStringContainsString('--port=', $output->stdout());
        self::assertStringContainsString('--dry-run', $output->stdout());
    }

    public function test_it_renders_help_for_runtime_status_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'runtime:status',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: runtime:status', $output->stdout());
        self::assertStringContainsString('Usage: php volt runtime:status [--driver=frankenphp] [--max-requests=1] [--emit-telemetry] [--strict] [--json]', $output->stdout());
        self::assertStringContainsString('--driver=', $output->stdout());
        self::assertStringContainsString('--max-requests=', $output->stdout());
        self::assertStringContainsString('--json', $output->stdout());
    }

    public function test_it_renders_help_for_config_status_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'config:status',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: config:status', $output->stdout());
        self::assertStringContainsString('Usage: php volt config:status [--emit-telemetry] [--strict] [--json]', $output->stdout());
        self::assertStringContainsString('--emit-telemetry', $output->stdout());
        self::assertStringContainsString('--strict', $output->stdout());
        self::assertStringContainsString('--json', $output->stdout());
    }

    public function test_it_renders_help_for_config_release_check_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'config:release-check',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: config:release-check', $output->stdout());
        self::assertStringContainsString('Usage: php volt config:release-check [--allow-missing-generation] [--allow-drift] [--emit-telemetry] [--json]', $output->stdout());
        self::assertStringContainsString('--allow-missing-generation', $output->stdout());
        self::assertStringContainsString('--allow-drift', $output->stdout());
        self::assertStringContainsString('--emit-telemetry', $output->stdout());
        self::assertStringContainsString('--json', $output->stdout());
    }

    public function test_it_renders_help_for_runtime_smoke_check_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'runtime:smoke-check',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: runtime:smoke-check', $output->stdout());
        self::assertStringContainsString('Usage: php volt runtime:smoke-check [--driver=frankenphp] [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--requests=/,GET:/health] [--budget-total-ms=50] [--budget-request-ms=25] [--emit-telemetry] [--json]', $output->stdout());
        self::assertStringContainsString('--artifact-dir=', $output->stdout());
        self::assertStringContainsString('--requests=', $output->stdout());
        self::assertStringContainsString('--budget-request-ms=', $output->stdout());
    }

    public function test_it_renders_help_for_runtime_budget_calibrate_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'runtime:budget-calibrate',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: runtime:budget-calibrate', $output->stdout());
        self::assertStringContainsString('Usage: php volt runtime:budget-calibrate [--driver=frankenphp] [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--requests=/,GET:/health] [--warmup=2] [--iterations=10] [--multiplier=1.25] [--emit-telemetry] [--json]', $output->stdout());
        self::assertStringContainsString('--warmup=', $output->stdout());
        self::assertStringContainsString('--iterations=', $output->stdout());
        self::assertStringContainsString('--multiplier=', $output->stdout());
    }

    public function test_it_renders_help_for_bootstrap_release_check_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'bootstrap:release-check',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: bootstrap:release-check', $output->stdout());
        self::assertStringContainsString('Usage: php volt bootstrap:release-check [--profile=release] [--artifact-dir=storage/framework/bootstrap] [--budget-total-ms=250] [--phase-budgets=DISCOVERING:25,BOOTING:50] [--emit-telemetry] [--json]', $output->stdout());
        self::assertStringContainsString('--phase-budgets=', $output->stdout());
        self::assertStringContainsString('--budget-total-ms=', $output->stdout());
    }

    public function test_it_renders_help_for_bootstrap_benchmark_command(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'bootstrap:benchmark',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: bootstrap:benchmark', $output->stdout());
        self::assertStringContainsString('Usage: php volt bootstrap:benchmark [--profile=release] [--artifact-dir=storage/framework/bootstrap/benchmark] [--budget-total-ms=250] [--phase-budgets=DISCOVERING:25,BOOTING:50] [--emit-telemetry] [--json]', $output->stdout());
        self::assertStringContainsString('--artifact-dir=', $output->stdout());
        self::assertStringContainsString('--phase-budgets=', $output->stdout());
    }

    public function test_it_resolves_help_for_aliases(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'help',
            'routes',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Command: route:list', $output->stdout());
        self::assertStringContainsString('Aliases: routes', $output->stdout());
    }

    public function test_it_suggests_similar_commands_when_a_command_is_unknown(): void
    {
        $output = new Output();
        $application = $this->application($output);

        $exitCode = $application->run([
            'volt',
            'view:cach',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Command [view:cach] is not defined.', $output->stderr());
        self::assertStringContainsString('Did you mean:', $output->stdout());
        self::assertStringContainsString('view:cache', $output->stdout());
    }

    public function test_it_registers_security_center_report_in_default_console_commands(): void
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-console-defaults-' . uniqid('', true);
        mkdir($basePath);

        try {
            $output = new Output();
            $application = new ConsoleApplication($basePath, [], $output);

            $exitCode = $application->run([
                'volt',
            ]);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString('auth:security-center:report', $output->stdout());
            self::assertStringContainsString('auth:security-center:revoke-device', $output->stdout());
        } finally {
            if (is_dir($basePath)) {
                rmdir($basePath);
            }
        }
    }

    private function application(Output $output): ConsoleApplication
    {
        return new ConsoleApplication(
            'C:\\W4\\Packages\\VoltStack\\app-skeleton',
            [
                new ServeCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RouteListCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RouteCacheCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RouteClearCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakeControllerCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakeComponentCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakeLayoutCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakePageCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakeViewCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new MakeActionCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new CacheClearCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new ViewCacheCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new ViewClearCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new ConfigReleaseCheckCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new ConfigStatusCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new BootstrapBenchmarkCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new BootstrapReleaseCheckCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new BootstrapStatusCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RuntimeBudgetCalibrateCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RuntimeSmokeCheckCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
                new RuntimeStatusCommand('C:\\W4\\Packages\\VoltStack\\app-skeleton'),
            ],
            $output,
        );
    }
}
