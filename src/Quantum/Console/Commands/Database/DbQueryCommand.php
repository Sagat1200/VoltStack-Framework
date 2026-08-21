<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Support\ConnectionRegistry;

final class DbQueryCommand extends Command
{
    public function name(): string
    {
        return 'db:query';
    }

    public function description(): string
    {
        return 'Ejecuta SQL raw desde CLI sobre una conexión configurada.';
    }

    public function usage(): string
    {
        return 'db:query "<sql>" [--connection=primary] [--json]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'sql' => 'Sentencia SQL raw a ejecutar.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--json' => 'Imprime resultados SELECT en JSON.',
            '--sql=' => 'Alternativa al argumento posicional para pasar la sentencia SQL.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();
        $allowRaw = (bool) $app->config('database.cli.allow_raw_query', $app->environment() !== 'production');

        if (!$allowRaw) {
            $output->error('db:query está deshabilitado por configuración en este entorno.');
            return 1;
        }

        $sql = $this->resolveSql($input);
        if ($sql === '') {
            $output->error('Debes indicar una sentencia SQL.');
            return 1;
        }

        /** @var ConnectionRegistry $registry */
        $registry = $app->make(ConnectionRegistry::class);
        $connectionName = is_string($input->option('connection')) ? (string) $input->option('connection') : null;

        try {
            $connection = $registry->connection($connectionName);
            $connection->connect();

            if ($this->isSelectQuery($sql)) {
                $rows = $connection->executeQuery($sql)->fetchAllAssoc();

                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    return 0;
                }

                $this->renderRows($rows, $output);
                $output->writeln(sprintf('Rows: %d', count($rows)));
                return 0;
            }

            $result = $connection->executeStatement($sql);
            $output->writeln(sprintf('Statement OK. Affected rows: %d', $result->affectedRows()));
            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:query failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function resolveSql(Input $input): string
    {
        $fromOption = $input->option('sql');
        if (is_string($fromOption) && trim($fromOption) !== '') {
            return trim($fromOption);
        }

        return trim(implode(' ', $input->arguments()));
    }

    private function isSelectQuery(string $sql): bool
    {
        $normalized = strtolower(ltrim($sql));

        foreach (['select', 'with', 'show', 'describe', 'desc', 'pragma', 'explain'] as $keyword) {
            if (str_starts_with($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderRows(array $rows, Output $output): void
    {
        if ($rows === []) {
            $output->writeln('No rows returned.');
            return;
        }

        $headers = array_keys($rows[0]);
        $widths = [];

        foreach ($headers as $header) {
            $widths[$header] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($headers as $header) {
                $widths[$header] = max($widths[$header], strlen($this->stringify($row[$header] ?? null)));
            }
        }

        $line = [];
        foreach ($headers as $header) {
            $line[] = str_pad($header, $widths[$header]);
        }

        $output->writeln(implode(' | ', $line));
        $output->writeln(str_repeat('-', max(3, strlen(implode('-|-', $line)))));

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = str_pad($this->stringify($row[$header] ?? null), $widths[$header]);
            }
            $output->writeln(implode(' | ', $cells));
        }
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? get_debug_type($value) : $json;
    }
}
