<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\CacheDoctorCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class CacheDoctorCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-doctor-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);

        $escapedBasePath = var_export($this->basePath, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$config = \$app->make(ConfigRepository::class);
\$config->set('cache.pools.default', ['store' => 'file', 'prefix' => 'app:', 'default_ttl' => 3600]);
\$config->set('cache.pools.sessions', ['store' => 'file', 'prefix' => 'sess:', 'default_ttl' => 7200]);
\$config->set('cache.default_pool', 'default');

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_diagnoses_default_pool_in_text_mode(): void
    {
        $command = new CacheDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:doctor']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $stdout = $output->stdout();
        self::assertStringContainsString('Diagnostico del pool:', $stdout);
        self::assertStringContainsString('default', $stdout);
        self::assertStringContainsString('outcome:', $stdout);
        self::assertStringContainsString('schema_version:', $stdout);
        self::assertStringContainsString('scope_fingerprint:', $stdout);
        self::assertStringContainsString('Efectos aplicados:', $stdout);
        self::assertStringContainsString('Configuracion:', $stdout);
    }

    public function test_it_diagnoses_specific_pool_via_option(): void
    {
        $command = new CacheDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:doctor', '--pool=sessions']),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('sessions', $output->stdout());
    }

    public function test_it_emits_doctor_as_json(): void
    {
        $command = new CacheDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:doctor', '--pool=sessions', '--json']),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('cache.pool.doctor.v1', $decoded['schema_version'] ?? null);
        self::assertSame('sessions', $decoded['pool'] ?? null);
        self::assertSame('available', $decoded['outcome'] ?? null);
        self::assertIsArray($decoded['effects'] ?? null);
        self::assertContains('pool_config_loaded', $decoded['effects']);
        self::assertArrayHasKey('configuration', $decoded);
        self::assertArrayHasKey('diagnostics', $decoded);
        self::assertSame('sess:', $decoded['configuration']['prefix'] ?? null);
    }

    public function test_verbose_mode_shows_repository_diagnostics(): void
    {
        $command = new CacheDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:doctor', '--verbose']),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Diagnosticos del repositorio:', $output->stdout());
    }

    public function test_it_reports_error_for_unknown_pool(): void
    {
        $command = new CacheDoctorCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv(['volt', 'cache:doctor', '--pool=nonexistent']),
            $output,
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No se pudo diagnosticar el pool', $output->stderr());
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
