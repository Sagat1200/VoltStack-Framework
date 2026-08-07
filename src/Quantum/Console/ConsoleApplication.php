<?php

declare(strict_types=1);

namespace Quantum\Console;

use Quantum\Bootstrap\Bootstrapper;
use Quantum\Console\Commands\CacheClearCommand;
use Quantum\Console\Commands\ControllerCompileClearCommand;
use Quantum\Console\Commands\ControllerCompileCommand;
use Quantum\Console\Commands\ControllerCompileWarmupCommand;
use Quantum\Console\Commands\MakeActionCommand;
use Quantum\Console\Commands\MakeComponentCommand;
use Quantum\Console\Commands\MakeControllerCommand;
use Quantum\Console\Commands\MakeLayoutCommand;
use Quantum\Console\Commands\MakePageCommand;
use Quantum\Console\Commands\MakeViewCommand;
use Quantum\Console\Commands\RouteCacheCommand;
use Quantum\Console\Commands\RouteClearCommand;
use Quantum\Console\Commands\RouteListCommand;
use Quantum\Console\Commands\ServeCommand;
use Quantum\Console\Commands\ViewCacheCommand;
use Quantum\Console\Commands\ViewClearCommand;
use Quantum\Console\Exceptions\CommandNotFoundException;
use Throwable;
use VoltStack\Framework\Application;

final class ConsoleApplication
{
    /**
     * @var array<string, Command>
     */
    private array $commands = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    private readonly Output $output;

    /**
     * @param iterable<int, Command> $commands
     */
    public function __construct(
        private readonly string $basePath,
        iterable $commands = [],
        ?Output $output = null,
    ) {
        $this->output = $output ?? new Output();
        $registered = false;

        foreach ($commands as $command) {
            $this->add($command);
            $registered = true;
        }

        if (! $registered) {
            $this->add(new ServeCommand($basePath));
            $this->add(new RouteListCommand($basePath));
            $this->add(new RouteCacheCommand($basePath));
            $this->add(new RouteClearCommand($basePath));
            $this->add(new MakeControllerCommand($basePath));
            $this->add(new MakeComponentCommand($basePath));
            $this->add(new MakeLayoutCommand($basePath));
            $this->add(new MakePageCommand($basePath));
            $this->add(new MakeViewCommand($basePath));
            $this->add(new MakeActionCommand($basePath));
            $this->add(new CacheClearCommand($basePath));
            $this->add(new ViewCacheCommand($basePath));
            $this->add(new ViewClearCommand($basePath));
            $this->add(new ControllerCompileCommand($basePath));
            $this->add(new ControllerCompileClearCommand($basePath));
            $this->add(new ControllerCompileWarmupCommand($basePath));
            $this->registerConfiguredCommands();
        }
    }

    public function add(Command $command): void
    {
        $this->commands[$command->name()] = $command;

        foreach ($command->aliases() as $alias) {
            $normalized = trim($alias);

            if ($normalized === '') {
                continue;
            }

            $this->aliases[$normalized] = $command->name();
        }
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $input = Input::fromArgv($argv);
        $output = $this->output;

        if ($input->command() === null || $input->command() === 'list') {
            $this->renderGeneralHelp($output, $input->script());

            return 0;
        }

        if ($input->command() === 'help') {
            $requestedCommand = $input->arguments()[0] ?? null;

            if ($requestedCommand === null) {
                $this->renderGeneralHelp($output, $input->script());

                return 0;
            }

            return $this->renderRequestedCommandHelp($requestedCommand, $output, $input->script());
        }

        if ($input->hasOption('help')) {
            return $this->renderRequestedCommandHelp($input->command(), $output, $input->script());
        }

        try {
            return $this->command($input->command())->handle($input, $output);
        } catch (CommandNotFoundException $exception) {
            $output->error($exception->getMessage());

            $suggestions = $this->suggestedCommands($input->command());

            if ($suggestions !== []) {
                $output->writeln();
                $output->writeln('Did you mean:');

                foreach ($suggestions as $suggestion) {
                    $output->writeln(sprintf('  - %s', $suggestion));
                }
            }

            $output->writeln();
            $this->renderGeneralHelp($output, $input->script());

            return 1;
        } catch (Throwable $exception) {
            $output->error(sprintf('VoltStack console error: %s', $exception->getMessage()));

            return 1;
        }
    }

    private function command(?string $name): Command
    {
        $resolved = $this->resolveCommandName($name);

        if ($resolved === null || ! isset($this->commands[$resolved])) {
            throw new CommandNotFoundException(sprintf('Command [%s] is not defined.', $name ?? 'null'));
        }

        return $this->commands[$resolved];
    }

    private function renderRequestedCommandHelp(?string $name, Output $output, string $script): int
    {
        try {
            $this->renderCommandHelp($this->command($name), $output, $script);

            return 0;
        } catch (CommandNotFoundException $exception) {
            $output->error($exception->getMessage());
            $output->writeln();
            $this->renderGeneralHelp($output, $script);

            return 1;
        }
    }

    private function renderGeneralHelp(Output $output, string $script): void
    {
        $output->writeln('VoltStack Console');
        $output->writeln();
        $output->writeln(sprintf('Usage: php %s <command> [options]', basename($script)));
        $output->writeln();

        foreach ($this->commandsByCategory() as $category => $commands) {
            $output->writeln(sprintf('%s:', $category));

            foreach ($commands as $command) {
                $suffix = $command->aliases() === []
                    ? ''
                    : sprintf(' [aliases: %s]', implode(', ', $command->aliases()));

                $output->writeln(sprintf('  %-18s %s%s', $command->name(), $command->description(), $suffix));
            }

            $output->writeln();
        }

        $output->writeln(sprintf('Use "php %s help <command>" for command details.', basename($script)));
        $output->writeln();
        $output->writeln(sprintf('Base path: %s', $this->basePath));
    }

    private function renderCommandHelp(Command $command, Output $output, string $script): void
    {
        $output->writeln(sprintf('Command: %s', $command->name()));
        $output->writeln($command->description());
        $output->writeln();
        $output->writeln(sprintf('Usage: php %s %s', basename($script), $command->usage()));

        if ($command->aliases() !== []) {
            $output->writeln();
            $output->writeln(sprintf('Aliases: %s', implode(', ', $command->aliases())));
        }

        $arguments = $command->argumentsHelp();
        $options = $command->optionsHelp();

        if ($arguments !== []) {
            $output->writeln();
            $output->writeln('Arguments:');

            foreach ($arguments as $name => $description) {
                $output->writeln(sprintf('  %-16s %s', $name, $description));
            }
        }

        if ($options !== []) {
            $output->writeln();
            $output->writeln('Options:');

            foreach ($options as $name => $description) {
                $output->writeln(sprintf('  %-16s %s', $name, $description));
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function suggestedCommands(?string $name): array
    {
        if ($name === null || $name === '') {
            return [];
        }

        $normalized = strtolower($name);
        $scores = [];

        foreach ($this->commandNamesForSuggestions() as $candidateName => $resolvedCommand) {
            $candidate = strtolower($candidateName);
            $distance = levenshtein($normalized, $candidate);

            if (str_contains($candidate, $normalized) || str_contains($normalized, $candidate)) {
                $distance = min($distance, 2);
            }

            if ($distance > max(3, (int) floor(strlen($candidate) / 3))) {
                continue;
            }

            $scores[$resolvedCommand] = isset($scores[$resolvedCommand])
                ? min($scores[$resolvedCommand], $distance)
                : $distance;
        }

        asort($scores);

        return array_slice(array_keys($scores), 0, 3);
    }

    private function resolveCommandName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return $this->aliases[$name] ?? $name;
    }

    /**
     * @return array<string, array<int, Command>>
     */
    private function commandsByCategory(): array
    {
        $grouped = [];

        foreach ($this->commands as $command) {
            $grouped[$command->category()][] = $command;
        }

        return $grouped;
    }

    /**
     * @return array<string, string>
     */
    private function commandNamesForSuggestions(): array
    {
        $names = [];

        foreach ($this->commands as $name => $command) {
            $names[$name] = $name;

            foreach ($command->aliases() as $alias) {
                $names[$alias] = $name;
            }
        }

        return $names;
    }

    private function registerConfiguredCommands(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        if (! is_dir($configPath)) {
            return;
        }

        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        foreach ((array) $app->config('app.providers', []) as $providerClass) {
            if (! is_string($providerClass) || trim($providerClass) === '') {
                continue;
            }

            $provider = $app->register($providerClass);

            foreach ($provider->commands() as $commandClass) {
                $this->add($this->makeProviderCommand($commandClass));
            }
        }
    }

    /**
     * @param class-string<Command> $commandClass
     */
    private function makeProviderCommand(string $commandClass): Command
    {
        if (! is_a($commandClass, Command::class, true)) {
            throw new \RuntimeException(sprintf(
                'Configured command [%s] must extend [%s].',
                $commandClass,
                Command::class,
            ));
        }

        return new $commandClass($this->basePath);
    }
}