<?php

declare(strict_types=1);

namespace Quantum\Console;

use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

abstract class Command
{
    public function __construct(protected readonly string $basePath) {}

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function handle(Input $input, Output $output): int;

    public function usage(): string
    {
        return $this->name();
    }

    public function category(): string
    {
        return 'General';
    }

    /**
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function argumentsHelp(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function optionsHelp(): array
    {
        return [];
    }

    protected function bootstrapApplication(bool $requirePublishedConfig = false): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf('The application bootstrap file could not be found at [%s].', $bootstrapPath));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        if ($requirePublishedConfig) {
            $this->assertPublishedConfiguration($app);
        }

        return $app;
    }

    private function assertPublishedConfiguration(Application $app): void
    {
        $status = $app->configStatusInspector()->inspect($app);

        if (! $status->hasActiveGeneration()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for this command, but no active configuration generation exists.',
            );
        }

        if (! $status->publishedMatchesEffective()) {
            throw new PublishedConfigurationRequiredException(
                'Published configuration is required for this command, but the effective snapshot differs from the active generation.',
            );
        }
    }

    protected function runInCommandRuntime(
        callable $callback,
        ?string $commandName = null,
        bool $requirePublishedConfig = false,
    ): mixed {
        $hadExceptionErrorHandler = ($GLOBALS['__voltstack_exceptionhandler_error_handler_registered'] ?? false) === true;
        $app = $this->bootstrapApplication($requirePublishedConfig);
        $scope = $app->make(ScopeManager::class);

        try {
            return $scope->runInCommand(
                fn(): mixed => $callback($app),
                $commandName ?? $this->name(),
            );
        } finally {
            $hasInstalledExceptionErrorHandler = ($GLOBALS['__voltstack_exceptionhandler_error_handler_registered'] ?? false) === true;

            if (! $hadExceptionErrorHandler && $hasInstalledExceptionErrorHandler) {
                restore_error_handler();
                $GLOBALS['__voltstack_exceptionhandler_error_handler_registered'] = false;
            }
        }
    }
}
