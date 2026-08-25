<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseOperationException;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\DatabaseDiagnosticSnapshot;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;
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
        return 'db:query "<sql>" [--connection=primary] [--json] [--pretend] [--timeout=30000] [--max-rows=1000] [--idempotency-key=key]';
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
            '--pretend' => 'Construye y muestra el plan sin ejecutar la operación.',
            '--sql=' => 'Alternativa al argumento posicional para pasar la sentencia SQL.',
            '--timeout=' => 'Override del deadline en milisegundos para esta ejecución.',
            '--max-rows=' => 'Override del máximo de filas permitidas para SELECT.',
            '--idempotency-key=' => 'Marca una mutación como idempotente para permitir retries si la policy lo habilita.',
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
        $resolvedConnectionName = $connectionName !== null && trim($connectionName) !== ''
            ? trim($connectionName)
            : $registry->defaultConnectionName();

        try {
            $connection = $registry->connection($connectionName);
            $connection->connect();
            $policy = $this->resolvePolicy($app->config('database', []), $input);
            $context = DatabaseContext::empty()
                ->withConnection($connection)
                ->withDeadlineMs($policy->timeoutMs)
                ->withLimits($policy->maxRows, $policy->maxDepth);

            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            $operation = new RawOperation(
                kind: $this->isSelectQuery($sql) ? OperationKind::RawQuery : OperationKind::RawExecute,
                sql: $sql,
                params: [],
                comment: $resolvedConnectionName,
                idempotencyKey: $this->resolveIdempotencyKey($input),
            );
            $plan = $runtime->plan($operation, $context, $policy);

            if (!$input->hasOption('json')) {
                $this->renderPlan($plan, $output);
            }

            if ($input->hasOption('pretend')) {
                if (!$input->hasOption('json')) {
                    $output->writeln('Dry-run activado: no se ejecutaron cambios.');
                }
                return 0;
            }

            $result = $runtime->execute($plan, $context);
            /** @var DatabaseDiagnosticSnapshot|null $diagnostic */
            $diagnostic = $result->debug['diagnostic'] ?? null;

            if ($this->isSelectQuery($sql)) {
                $rows = $result->queryResult?->fetchAllAssoc() ?? [];

                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    return 0;
                }

                $this->renderRows($rows, $output);
                $output->writeln(sprintf('Rows: %d', count($rows)));
                if ($diagnostic instanceof DatabaseDiagnosticSnapshot) {
                    $this->renderDiagnostic($diagnostic, $output);
                }
                return 0;
            }

            $output->writeln(sprintf('Statement OK. Affected rows: %d', $result->affectedRows));
            if ($diagnostic instanceof DatabaseDiagnosticSnapshot) {
                $this->renderDiagnostic($diagnostic, $output);
            }
            return 0;
        } catch (DatabaseOperationException $e) {
            $snapshot = $e->snapshot;
            $output->error(sprintf(
                'db:query failed: failure=%s outcome=%s attempts=%d fingerprint=%s circuit=%s remaining_ms=%d message=%s',
                $e->failure->value,
                $snapshot->outcome,
                $snapshot->attempts,
                $snapshot->fingerprint,
                $snapshot->circuitState,
                $snapshot->deadlineRemainingMs,
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ));
            foreach ($snapshot->events as $event) {
                $output->error(sprintf('  event: %s at=%s', $event->name, $event->at));
            }
            return 1;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:query failed: %s', $e->getMessage()));
            return 1;
        }
    }

    /**
     * @param mixed $databaseConfig
     */
    private function resolvePolicy(mixed $databaseConfig, Input $input): DatabaseExecutionPolicy
    {
        $policy = DatabaseExecutionPolicy::fromConfig(is_array($databaseConfig) ? $databaseConfig : []);

        $timeout = $this->resolvePositiveIntOption($input, 'timeout');
        if ($timeout !== null) {
            $policy = $policy->withTimeoutMs($timeout);
        }

        $maxRows = $this->resolvePositiveIntOption($input, 'max-rows');
        if ($maxRows !== null) {
            $policy = $policy->withMaxRows($maxRows);
        }

        return $policy;
    }

    private function renderPlan(DatabaseOperationPlan $plan, Output $output): void
    {
        $output->writeln(sprintf(
            'Plan: kind=%s fingerprint=%s sql=%s',
            $plan->operation->kind->value,
            $plan->fingerprint,
            $plan->safeSqlPreview,
        ));
        $output->writeln(sprintf(
            'Budget: connection=%s driver=%s timeout_ms=%d max_rows=%d depth=%d/%d retry_limit=%d retryable=%s idempotency=%s',
            $plan->connectionName !== '' ? $plan->connectionName : 'default',
            $plan->driver,
            $plan->deadline->remainingMs(),
            $plan->maxRows,
            $plan->detectedDepth,
            $plan->maxDepth,
            $plan->retryLimit,
            $plan->retryable ? 'yes' : 'no',
            $plan->operation->idempotencyKey !== null && trim($plan->operation->idempotencyKey) !== '' ? 'present' : 'absent',
        ));
    }

    private function renderDiagnostic(DatabaseDiagnosticSnapshot $snapshot, Output $output): void
    {
        $output->writeln(sprintf(
            'Diagnostic: outcome=%s attempts=%d duration_ms=%d rows=%d affected=%d slow=%s circuit=%s remaining_ms=%d',
            $snapshot->outcome,
            $snapshot->attempts,
            $snapshot->durationMs,
            $snapshot->rowsRead,
            $snapshot->affectedRows,
            $snapshot->slowQuery ? 'yes' : 'no',
            $snapshot->circuitState,
            $snapshot->deadlineRemainingMs,
        ));
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

    private function resolvePositiveIntOption(Input $input, string $option): ?int
    {
        $value = $input->option($option);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }

    private function resolveIdempotencyKey(Input $input): ?string
    {
        $value = $input->option('idempotency-key');
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
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
