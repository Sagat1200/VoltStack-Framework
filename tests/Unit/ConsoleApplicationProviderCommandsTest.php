<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Console\Command;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class ConsoleApplicationProviderCommandsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-console-provider-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'providers' => [
        \VoltStack\Test\Unit\ConsoleCommandDiscoveryProvider::class,
    ],
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_discovers_commands_exposed_by_registered_providers(): void
    {
        $output = new Output();
        $application = new ConsoleApplication($this->basePath, [], $output);

        $exitCode = $application->run([
            'volt',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('frontend:install', $output->stdout());
    }

    public function test_it_can_require_published_configuration_for_provider_command_discovery(): void
    {
        $this->publishCurrentConfiguration();

        $output = new Output();
        $application = new ConsoleApplication($this->basePath, [], $output);

        $exitCode = $application->run([
            'volt',
            '--require-published-config',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('frontend:install', $output->stdout());
    }

    public function test_it_fails_provider_command_discovery_when_published_configuration_is_required_but_missing(): void
    {
        $output = new Output();
        $application = new ConsoleApplication($this->basePath, [], $output);

        $exitCode = $application->run([
            'volt',
            '--require-published-config',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Published configuration is required for bootstrap', $output->stderr());
    }

    private function publishCurrentConfiguration(): string
    {
        $app = new Application($this->basePath);
        $repository = $app->make(ConfigRepository::class);
        $repository->loadPath($this->basePath . DIRECTORY_SEPARATOR . 'config');

        $codec = $app->configSnapshotCodec();
        $baseSnapshot = $repository->snapshot(provenance: $repository->provenance());
        $publishedSnapshot = $repository->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        );

        $artifact = $app->configManifestStore()->publish($publishedSnapshot);
        $app->configManifestStore()->activateGeneration($artifact->generationId());

        return $artifact->generationId();
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (! is_array($items)) {
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

final class ConsoleCommandDiscoveryProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function commands(): array
    {
        return [
            ConsoleCommandDiscoveryCommand::class,
        ];
    }
}

final class ConsoleCommandDiscoveryCommand extends Command
{
    public function name(): string
    {
        return 'frontend:install';
    }

    public function description(): string
    {
        return 'Discovered provider command for console integration tests.';
    }

    public function category(): string
    {
        return 'Frontend';
    }

    public function handle(Input $input, Output $output): int
    {
        $output->writeln('frontend:install');

        return 0;
    }
}
