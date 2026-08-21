<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;

final class OrmClearCacheCommand extends Command
{
    public function name(): string
    {
        return 'orm:clear-cache';
    }

    public function description(): string
    {
        return 'Limpia el cache de metadata ORM (L1/L2/L3).';
    }

    public function usage(): string
    {
        return 'orm:clear-cache';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        /** @var MetadataManagerInterface $metadata */
        $metadata = $app->make(MetadataManagerInterface::class);

        try {
            $metadata->clearCache();
            $output->writeln('ORM metadata cache cleared.');
            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('orm:clear-cache failed: %s', $e->getMessage()));
            return 1;
        }
    }
}
