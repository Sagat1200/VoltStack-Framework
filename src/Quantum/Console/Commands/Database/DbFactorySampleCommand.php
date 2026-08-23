<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Factory\FactoryDiscovery;
use Quantum\Database\Factory\FactoryManager;
use VoltStack\Framework\Application;

final class DbFactorySampleCommand extends Command
{
    public function name(): string
    {
        return 'db:factory-sample';
    }

    public function description(): string
    {
        return 'Genera muestras deterministas desde una factory sin persistir datos.';
    }

    public function usage(): string
    {
        return 'db:factory-sample <factory> [--count=1] [--seed=12345] [--state=admin,inactive] [--json]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'factory' => 'Nombre, clase o entityClass de la factory a muestrear.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--count=' => 'Cantidad de entidades a generar.',
            '--seed=' => 'Semilla determinista para la generación.',
            '--state=' => 'Lista separada por comas de states a aplicar en orden.',
            '--json' => 'Imprime el resultado en JSON.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $target = trim($input->arguments()[0] ?? '');
        if ($target === '') {
            $output->error('Debes indicar una factory.');
            return 1;
        }

        try {
            $app = $this->bootstrapApplication();
            $manager = new FactoryManager(
                app: $app,
                discovery: new FactoryDiscovery(
                    basePath: $app->basePath(),
                    paths: $this->resolvePaths($app),
                    classes: $this->resolveClasses($app),
                ),
                defaultSeed: $this->resolveDefaultSeed($app),
            );

            $builder = $manager->factory($target)->count($this->resolveCount($input));
            $seed = $this->resolveSeed($input);
            if ($seed !== null) {
                $builder = $builder->seed($seed);
            }

            foreach ($this->resolveStates($input) as $state) {
                $builder = $builder->state($state);
            }

            $rows = array_map($this->normalizeObject(...), $builder->make());
            $json = (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($input->hasOption('json')) {
                $output->writeln($json);
                return 0;
            }

            $output->writeln($json);
            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:factory-sample failed: %s', $e->getMessage()));
            return 1;
        }
    }

    /**
     * @return list<string>
     */
    private function resolvePaths(Application $app): array
    {
        $paths = $app->config('database.factories.paths', ['database/factories']);
        return is_array($paths) ? array_values($paths) : ['database/factories'];
    }

    /**
     * @return list<class-string>
     */
    private function resolveClasses(Application $app): array
    {
        $classes = $app->config('database.factories.classes', []);
        return is_array($classes) ? array_values($classes) : [];
    }

    private function resolveDefaultSeed(Application $app): int
    {
        return (int) $app->config('database.factories.default_seed', 12345);
    }

    private function resolveCount(Input $input): int
    {
        $value = $input->option('count');
        if (!is_string($value) || trim($value) === '') {
            return 1;
        }

        return max(1, (int) $value);
    }

    private function resolveSeed(Input $input): ?int
    {
        $value = $input->option('seed');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private function resolveStates(Input $input): array
    {
        $value = $input->option('state');
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $state): bool => $state !== ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeObject(object $entity): array
    {
        $reflection = new \ReflectionObject($entity);
        $data = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $data[$property->getName()] = $property->getValue($entity);
        }

        ksort($data);

        return $data;
    }
}
