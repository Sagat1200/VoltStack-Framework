<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ContainerStatusCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class ContainerStatusCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-container-status-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Container Status',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'container.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [];
PHP
        );

        $this->writeBootstrapApp(false);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_container_status_command_renders_human_summary_and_returns_zero_when_healthy(): void
    {
        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Container status:', $output->stdout());
        self::assertStringContainsString('  Format: full', $output->stdout());
        self::assertStringContainsString('Services:', $output->stdout());
        self::assertStringContainsString('Lifetimes: singleton=', $output->stdout());
        self::assertStringContainsString('Closure bindings:', $output->stdout());
        self::assertStringContainsString('Instance bindings:', $output->stdout());
        self::assertStringContainsString('Issues: 0 (matched 0)', $output->stdout());
    }

    public function test_container_status_command_renders_stable_json_payload_with_issue_and_graph_snapshot(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('container:status', $decoded['command'] ?? null);
        self::assertIsArray($decoded['report'] ?? null);
        self::assertFalse($decoded['report']['healthy'] ?? null);
        self::assertGreaterThanOrEqual(1, $decoded['report']['issue_count'] ?? 0);
        self::assertGreaterThanOrEqual(1, $decoded['report']['issue_breakdown']['missing_class'] ?? 0);
        self::assertGreaterThanOrEqual(1, $decoded['report']['service_count'] ?? 0);
        self::assertGreaterThanOrEqual(1, $decoded['report']['lifetime_breakdown']['transient'] ?? 0);
        self::assertIsArray($decoded['report']['issues'][0] ?? null);
        self::assertSame('missing_class', $decoded['report']['issues'][0]['code'] ?? null);
        self::assertSame('definition', $decoded['report']['issues'][0]['phase'] ?? null);
        self::assertSame('binding', $decoded['report']['issues'][0]['origin'] ?? null);
        self::assertSame(['container.missing.class'], $decoded['report']['issues'][0]['path'] ?? null);
        self::assertIsArray($decoded['report']['graph'] ?? null);
        self::assertArrayHasKey('services', $decoded['report']['graph']);
        self::assertArrayHasKey('aliases', $decoded['report']['graph']);
        self::assertArrayHasKey('issues', $decoded['report']['graph']);
        self::assertArrayHasKey('has_issues', $decoded['report']['graph']);
    }

    public function test_container_status_command_strict_mode_returns_failure_when_graph_has_issues(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--strict',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Issue breakdown:', $output->stdout());
        self::assertStringContainsString('Missing class: 1', $output->stdout());
        self::assertStringContainsString('[missing_class][definition]', $output->stdout());
        self::assertStringContainsString('(path: container.missing.class)', $output->stdout());
    }

    public function test_container_status_command_strict_mode_passes_when_graph_is_healthy(): void
    {
        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--strict',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
    }

    public function test_container_status_command_filters_issues_by_code_and_makes_strict_pass_when_filter_has_zero_match(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--strict',
                '--code=scope_capture_violation',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame('scope_capture_violation', $decoded['report']['filters']['code'] ?? null);
        self::assertSame(0, $decoded['report']['filtered_issue_count'] ?? 1);
        self::assertTrue($decoded['report']['healthy_under_filter'] ?? false);
        self::assertFalse($decoded['report']['healthy'] ?? true);
        self::assertSame([], $decoded['report']['issues'] ?? null);
    }

    public function test_container_status_command_filters_issues_by_service_id_and_preserves_issue_payload(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--service=container.missing.class',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame('container.missing.class', $decoded['report']['filters']['service'] ?? null);
        self::assertGreaterThanOrEqual(1, $decoded['report']['filtered_issue_count'] ?? 0);
        self::assertSame('missing_class', $decoded['report']['issues'][0]['code'] ?? null);
        self::assertSame('container.missing.class', $decoded['report']['issues'][0]['service_id'] ?? null);
        self::assertSame(['container.missing.class'], $decoded['report']['issues'][0]['path'] ?? null);
    }

    public function test_container_status_command_brief_format_omits_issues_and_graph_in_payload_and_human_output(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $jsonOutput = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--format=brief',
                '--json',
            ]),
            $jsonOutput,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($jsonOutput->stdout()), true);
        self::assertSame('brief', $decoded['report']['format'] ?? null);
        self::assertSame([], $decoded['report']['issues'] ?? ['not-empty']);
        self::assertSame([], $decoded['report']['graph'] ?? ['not-empty']);
        self::assertSame(1, $decoded['report']['filtered_issue_count'] ?? 0);

        $humanOutput = new Output();
        $exitCodeHuman = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--format=brief',
            ]),
            $humanOutput,
        );

        self::assertSame(0, $exitCodeHuman);
        self::assertStringNotContainsString('Issue breakdown:', $humanOutput->stdout());
        self::assertStringContainsString('Format: brief', $humanOutput->stdout());
    }

    public function test_container_status_command_graph_format_reports_graph_even_without_matched_issues(): void
    {
        $command = new ContainerStatusCommand($this->basePath);
        $jsonOutput = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--format=graph',
                '--json',
            ]),
            $jsonOutput,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($jsonOutput->stdout()), true);
        self::assertSame('graph', $decoded['report']['format'] ?? null);
        self::assertSame(0, $decoded['report']['filtered_issue_count'] ?? 1);
        self::assertArrayHasKey('services', $decoded['report']['graph'] ?? []);
        self::assertArrayHasKey('aliases', $decoded['report']['graph'] ?? []);

        $humanOutput = new Output();
        $exitCodeHuman = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--format=graph',
            ]),
            $humanOutput,
        );

        self::assertSame(0, $exitCodeHuman);
        self::assertStringContainsString('Graph snapshot:', $humanOutput->stdout());
    }

    public function test_container_status_command_issue_limit_zero_keeps_all_issues_and_strict_detects_matching_issue(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--strict',
                '--code=missing_class',
                '--issue-limit=0',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame(0, $decoded['report']['applied_issue_limit'] ?? 1);
        self::assertSame(0, $decoded['report']['truncated_issue_count'] ?? 1);
        self::assertGreaterThanOrEqual(1, $decoded['report']['filtered_issue_count'] ?? 0);
        self::assertCount(1, $decoded['report']['issues'] ?? []);
    }

    public function test_container_status_command_default_issue_limit_documented_in_options_help(): void
    {
        $command = new ContainerStatusCommand($this->basePath);
        $help = $command->optionsHelp();

        self::assertStringContainsString('250', $help['--issue-limit=<limit>'] ?? '');
    }

    public function test_container_status_command_severity_error_filter_matches_missing_class_and_strict_fails(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--severity=error',
                '--strict',
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame('error', $decoded['report']['filters']['severity'] ?? null);
        self::assertGreaterThanOrEqual(1, $decoded['report']['filtered_issue_count'] ?? 0);
        self::assertSame('missing_class', $decoded['report']['issues'][0]['code'] ?? null);
        self::assertSame('error', $decoded['report']['issues'][0]['severity'] ?? null);
    }

    public function test_container_status_command_severity_warning_filter_excludes_missing_class_and_strict_passes(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerStatusCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:status',
                '--severity=warning',
                '--strict',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertFalse($decoded['report']['healthy'] ?? true);
        self::assertTrue($decoded['report']['healthy_under_filter'] ?? false);
        self::assertSame(0, $decoded['report']['filtered_issue_count'] ?? 1);
    }

    private function writeBootstrapApp(bool $registerMissingClassBinding): void
    {
        $escapedBasePath = var_export($this->basePath, true);
        $bindingBlock = $registerMissingClassBinding
            ? '$app->bind(\'container.missing.class\', \'VoltStack\\\\Missing\\\\Container\\\\Status\\\\Service\');'
            : '';

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();
{$bindingBlock}
\$app->boot();

return \$app;
PHP
        );
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
