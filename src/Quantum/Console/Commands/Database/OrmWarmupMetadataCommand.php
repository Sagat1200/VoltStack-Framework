<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;

final class OrmWarmupMetadataCommand extends Command
{
    public function name(): string
    {
        return 'orm:warmup-metadata';
    }

    public function description(): string
    {
        return 'Precalienta el cache de metadata ORM (L1/L2/L3).';
    }

    public function usage(): string
    {
        return 'orm:warmup-metadata [Entity\\Class\\One Entity\\Class\\Two]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'entities...' => 'Clases de entidad opcionales. Si no se indican, se descubren desde la config.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        /** @var MetadataManagerInterface $metadata */
        $metadata = $app->make(MetadataManagerInterface::class);

        $classes = $input->arguments();
        if ($classes === []) {
            /** @var EntityDiscovery $discovery */
            $discovery = $app->make(EntityDiscovery::class);
            $classes = $discovery->discover();
        }

        if ($classes === []) {
            $output->writeln('No se encontraron entidades ORM para warmup.');
            return 0;
        }

        try {
            $count = $metadata->warmup($classes);
            $output->writeln(sprintf('Metadata warmup OK. Entities: %d', $count));

            foreach ($classes as $class) {
                $output->writeln(sprintf('  - %s', $class));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('orm:warmup-metadata failed: %s', $e->getMessage()));
            return 1;
        }
    }
}
