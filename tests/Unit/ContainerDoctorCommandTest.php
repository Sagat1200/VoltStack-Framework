<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\ContainerDoctorCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class ContainerDoctorCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-container-doctor-command-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Container Doctor',
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

    public function test_container_doctor_reports_clean_plan_when_no_issues_match(): void
    {
        $command = new ContainerDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:doctor',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Container doctor:', $output->stdout());
        self::assertStringContainsString('Pending remediation groups: 0', $output->stdout());
        self::assertStringContainsString('no issues matched', $output->stdout());
    }

    public function test_container_doctor_groups_missing_class_under_remediation_and_strict_fails(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:doctor',
                '--strict',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Pending remediation groups: 1', $output->stdout());
        self::assertStringContainsString('[ERROR][missing_class]', $output->stdout());
        self::assertStringContainsString('container.missing.class', $output->stdout());
        self::assertStringContainsString('Sample paths: container.missing.class', $output->stdout());
    }

    public function test_container_doctor_apply_hint_emits_suggested_php_for_missing_class(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:doctor',
                '--apply-hint',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame('container:doctor', $decoded['command'] ?? null);
        self::assertSame(1, $decoded['report']['pending_remediation_count'] ?? 0);
        self::assertSame('missing_class', $decoded['report']['remediations'][0]['code'] ?? null);
        self::assertSame('error', $decoded['report']['remediations'][0]['severity'] ?? null);
        self::assertContains('container.missing.class', $decoded['report']['remediations'][0]['services'] ?? []);
        self::assertArrayHasKey('apply_hint', $decoded['report']['remediations'][0] ?? []);
        self::assertSame('registrar o corregir la clase concreta que falta', $decoded['report']['remediations'][0]['apply_hint']['intent'] ?? '');
        self::assertNotEmpty($decoded['report']['remediations'][0]['apply_hint']['suggested_php'] ?? []);
        self::assertStringContainsString('Container_missing_class::class', (string) ($decoded['report']['remediations'][0]['apply_hint']['suggested_php'][0] ?? ''));
    }

    public function test_container_doctor_severity_filter_excludes_non_matching_groups(): void
    {
        $this->writeBootstrapApp(true);

        $command = new ContainerDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'container:doctor',
                '--severity=warning',
                '--strict',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);

        $decoded = json_decode(trim($output->stdout()), true);
        self::assertSame('warning', $decoded['report']['filters']['severity'] ?? null);
        self::assertSame(0, $decoded['report']['pending_remediation_count'] ?? 1);
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
