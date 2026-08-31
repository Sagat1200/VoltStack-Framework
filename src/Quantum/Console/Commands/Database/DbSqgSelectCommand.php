<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Query\Enum\JoinType;
use Quantum\Database\Query\Enum\Order;
use Quantum\Database\Query\SelectQueryBuilder;
use Quantum\Database\Support\ConnectionRegistry;

final class DbSqgSelectCommand extends Command
{
    public function name(): string
    {
        return 'db:sqg-select';
    }

    public function description(): string
    {
        return 'Construye un SELECT sobre SQG desde un spec JSON y expone el summary real del optimizer.';
    }

    public function usage(): string
    {
        return 'db:sqg-select --spec=\'{"from":{"table":"users","alias":"u"},"select":["u.id"]}\' [--spec-file=path] [--connection=primary] [--pretend] [--json]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--spec=' => 'Spec JSON inline con from/select/joins/where/order_by/params/limit/offset.',
            '--spec-file=' => 'Ruta a un archivo JSON con el spec SQG.',
            '--connection=' => 'Nombre de la conexión configurada a utilizar.',
            '--pretend' => 'Compila y muestra SQL + summary del pipeline SQG sin ejecutar.',
            '--json' => 'Imprime el resultado en JSON.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $app = $this->bootstrapApplication();
            $spec = $this->resolveSpec($input);

            /** @var ConnectionRegistry $registry */
            $registry = $app->make(ConnectionRegistry::class);
            $connectionName = is_string($input->option('connection')) ? trim($input->option('connection')) : '';
            $connection = $registry->connection($connectionName !== '' ? $connectionName : null);
            $connection->connect();

            /** @var DatabaseContext $context */
            $context = $app->make(DatabaseContext::class);
            $context = $context->withConnection($connection);

            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            $builder = $this->buildBuilderFromSpec($spec, $connection, $context, $runtime);
            $sql = $builder->getSQL();
            $summary = $builder->getLastPipelineSummary();

            if (!is_array($summary)) {
                throw new \RuntimeException('No fue posible construir el pipeline SQG para este spec.');
            }

            if ($input->hasOption('pretend')) {
                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode([
                        'pretend' => true,
                        'sql' => $sql,
                        'pipeline' => $summary,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    return 0;
                }

                $this->renderPretendSummary($sql, $summary, $output);
                $output->writeln('Dry-run activado: no se ejecutaron cambios.');
                return 0;
            }

            $rows = $builder->fetchAllAssociative();

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode([
                    'pretend' => false,
                    'sql' => $sql,
                    'pipeline' => $summary,
                    'rows' => $rows,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return 0;
            }

            $this->renderPretendSummary($sql, $summary, $output);
            $this->renderRows($rows, $output);
            $output->writeln(sprintf('Rows: %d', count($rows)));

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:sqg-select failed: %s', $e->getMessage()));
            return 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSpec(Input $input): array
    {
        $inline = $input->option('spec');
        if (is_string($inline) && trim($inline) !== '') {
            return $this->decodeSpec(trim($inline), 'spec');
        }

        $file = $input->option('spec-file');
        if (is_string($file) && trim($file) !== '') {
            $path = trim($file);
            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('No existe el archivo de spec [%s].', $path));
            }

            $contents = file_get_contents($path);
            if (!is_string($contents) || trim($contents) === '') {
                throw new \RuntimeException(sprintf('El archivo de spec [%s] está vacío.', $path));
            }

            return $this->decodeSpec($contents, 'spec-file');
        }

        throw new \RuntimeException('Debes indicar --spec o --spec-file con un spec JSON válido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSpec(string $json, string $source): array
    {
        $attempts = $this->candidateSpecPayloads($json);
        $lastException = null;

        foreach ($attempts as $candidate) {
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);

                if (!is_array($decoded)) {
                    throw new \RuntimeException(sprintf('El %s debe decodificar a un objeto JSON.', $source));
                }

                return $decoded;
            } catch (\JsonException | \RuntimeException $e) {
                $lastException = $e;
            }
        }

        $message = $lastException?->getMessage() ?? 'Syntax error';
        throw new \RuntimeException(sprintf('No se pudo decodificar %s JSON: %s', $source, $message), previous: $lastException);
    }

    /**
     * @return list<string>
     */
    private function candidateSpecPayloads(string $raw): array
    {
        $trimmed = trim($raw);
        $candidates = [$trimmed];

        $unwrapped = $this->unwrapQuotedPayload($trimmed);
        if ($unwrapped !== $trimmed) {
            $candidates[] = $unwrapped;
        }

        foreach ([$trimmed, $unwrapped] as $candidate) {
            $normalized = str_replace(['\"', '""'], ['"', '"'], $candidate);
            if ($normalized !== $candidate) {
                $candidates[] = $normalized;
            }
        }

        foreach ([$trimmed, $unwrapped] as $candidate) {
            $relaxed = $this->normalizeRelaxedSpecPayload($candidate);
            if ($relaxed !== $candidate) {
                $candidates[] = $relaxed;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn(string $value): bool => $value !== '')));
    }

    private function unwrapQuotedPayload(string $payload): string
    {
        if (strlen($payload) < 2) {
            return $payload;
        }

        $first = $payload[0];
        $last = $payload[strlen($payload) - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($payload, 1, -1);
        }

        return $payload;
    }

    private function normalizeRelaxedSpecPayload(string $payload): string
    {
        $normalized = trim(str_replace('\\', '', $payload));
        if ($normalized === '' || (!str_starts_with($normalized, '{') && !str_starts_with($normalized, '['))) {
            return $payload;
        }

        $normalized = preg_replace('/([{\[,]\s*)([A-Za-z_][A-Za-z0-9_\-]*)(\s*:)/', '$1"$2"$3', $normalized) ?? $normalized;
        $normalized = preg_replace_callback(
            '/(:\s*)([A-Za-z_][A-Za-z0-9_.\-]*)(?=\s*[,}\]])/',
            function (array $matches): string {
                $value = $matches[2];

                if (in_array(strtolower($value), ['true', 'false', 'null'], true)) {
                    return $matches[1] . strtolower($value);
                }

                return $matches[1] . '"' . $value . '"';
            },
            $normalized,
        ) ?? $normalized;

        $normalized = preg_replace_callback(
            '/([\[,]\s*)([A-Za-z_][A-Za-z0-9_.\-]*)(?=\s*[,}\]])/',
            function (array $matches): string {
                $value = $matches[2];

                if (in_array(strtolower($value), ['true', 'false', 'null'], true)) {
                    return $matches[1] . strtolower($value);
                }

                return $matches[1] . '"' . $value . '"';
            },
            $normalized,
        ) ?? $normalized;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function buildBuilderFromSpec(
        array $spec,
        mixed $connection,
        DatabaseContext $context,
        DatabaseOperationRuntime $runtime,
    ): SelectQueryBuilder {
        $from = $spec['from'] ?? null;
        if (!is_array($from)) {
            throw new \RuntimeException('El spec requiere [from] con {table, alias}.');
        }

        $fromTable = trim((string) ($from['table'] ?? ''));
        $fromAlias = trim((string) ($from['alias'] ?? ''));
        if ($fromTable === '' || $fromAlias === '') {
            throw new \RuntimeException('El bloque [from] requiere [table] y [alias] no vacíos.');
        }

        $builder = new SelectQueryBuilder($connection, $context, $runtime);
        $builder = $builder->from($fromTable, $fromAlias);

        if (($spec['distinct'] ?? false) === true) {
            $builder = $builder->distinct();
        }

        $selects = $spec['select'] ?? ['*'];
        if (!is_array($selects) || $selects === []) {
            $selects = ['*'];
        }
        $builder = $builder->select(array_map(static fn(mixed $value): string => (string) $value, $selects));

        foreach (($spec['joins'] ?? []) as $join) {
            if (!is_array($join)) {
                continue;
            }

            $type = $this->mapJoinType((string) ($join['type'] ?? 'INNER'));
            $joinFromAlias = trim((string) ($join['from_alias'] ?? ''));
            $joinTable = trim((string) ($join['table'] ?? ''));
            $joinAlias = trim((string) ($join['alias'] ?? ''));
            $joinOn = isset($join['on']) ? trim((string) $join['on']) : null;

            if ($joinFromAlias === '' || $joinTable === '' || $joinAlias === '') {
                throw new \RuntimeException('Cada join requiere [from_alias], [table] y [alias].');
            }

            $builder = $builder->join($joinFromAlias, $joinTable, $joinAlias, $joinOn, $type);
        }

        foreach (($spec['where'] ?? []) as $index => $where) {
            $expr = trim((string) $where);
            if ($expr === '') {
                continue;
            }

            $builder = $index === 0
                ? $builder->where($expr)
                : $builder->andWhere($expr);
        }

        $groupBy = $spec['group_by'] ?? [];
        if (is_array($groupBy) && $groupBy !== []) {
            $builder = $builder->groupBy(array_map(static fn(mixed $value): string => (string) $value, $groupBy));
        }

        foreach (($spec['having'] ?? []) as $index => $having) {
            $expr = trim((string) $having);
            if ($expr === '') {
                continue;
            }

            $builder = $index === 0
                ? $builder->having($expr)
                : $builder->andHaving($expr);
        }

        foreach (($spec['order_by'] ?? []) as $index => $orderBy) {
            if (!is_array($orderBy)) {
                continue;
            }

            $expr = trim((string) ($orderBy['expr'] ?? ''));
            if ($expr === '') {
                continue;
            }

            $direction = $this->mapOrderDirection((string) ($orderBy['direction'] ?? 'ASC'));
            $builder = $index === 0
                ? $builder->orderBy($expr, $direction)
                : $builder->addOrderBy($expr, $direction);
        }

        foreach (($spec['params'] ?? []) as $name => $value) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $builder = $builder->setParameter(trim($name), $value);
        }

        if (isset($spec['limit'])) {
            $builder = $builder->setMaxResults((int) $spec['limit']);
        }

        if (isset($spec['offset'])) {
            $builder = $builder->setFirstResult((int) $spec['offset']);
        }

        return $builder;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderPretendSummary(string $sql, array $summary, Output $output): void
    {
        $output->writeln(sprintf('SQG SQL: %s', $sql));
        $output->writeln(sprintf(
            'Optimizer: strategy=%s candidate=%s estimated_cost=%s',
            (string) ($summary['optimizer']['strategy'] ?? 'n/a'),
            (string) ($summary['optimizer']['selected_candidate_id'] ?? 'n/a'),
            (string) ($summary['optimizer']['estimated_cost'] ?? 'n/a'),
        ));

        $appliedRules = $summary['optimizer']['applied_rules'] ?? [];
        if (is_array($appliedRules) && $appliedRules !== []) {
            $output->writeln(sprintf('Rules: %s', implode(', ', array_map('strval', $appliedRules))));
        }

        $optimizerCandidates = $summary['optimizer']['candidates'] ?? [];
        if (is_array($optimizerCandidates) && $optimizerCandidates !== []) {
            $output->writeln('Candidates:');
            foreach ($optimizerCandidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $rules = is_array($candidate['applied_rules'] ?? null)
                    ? implode(',', array_map('strval', $candidate['applied_rules']))
                    : '';

                $output->writeln(sprintf(
                    '  - %s cost=%s selected=%s rules=%s',
                    (string) ($candidate['id'] ?? 'n/a'),
                    (string) ($candidate['estimated_cost'] ?? 'n/a'),
                    (($candidate['selected'] ?? false) === true) ? 'yes' : 'no',
                    $rules !== '' ? $rules : 'none',
                ));
            }
        }

        $joinReorder = $summary['optimizer']['join_reorder'] ?? null;
        if (is_array($joinReorder)) {
            $output->writeln(sprintf(
                'Join reorder: selected=%s candidates=%s score=%s',
                (string) ($joinReorder['selected_signature'] ?? 'n/a'),
                (string) ($joinReorder['candidate_count'] ?? '0'),
                (string) ($joinReorder['selected_score'] ?? 'n/a'),
            ));

            foreach (($joinReorder['candidates'] ?? []) as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $output->writeln(sprintf(
                    '  - %s base=%s score=%s joins=%s',
                    (string) ($candidate['signature'] ?? 'n/a'),
                    (string) ($candidate['base_alias'] ?? 'n/a'),
                    (string) ($candidate['score'] ?? 'n/a'),
                    implode(',', is_array($candidate['join_aliases'] ?? null) ? array_map('strval', $candidate['join_aliases']) : []),
                ));
            }
        }

        $output->writeln(sprintf(
            'Planner: logical_root=%s physical_root=%s fingerprint=%s',
            (string) ($summary['planner']['logical_root_operator'] ?? 'n/a'),
            (string) ($summary['planner']['physical_root_strategy'] ?? 'n/a'),
            (string) ($summary['planner']['fingerprint'] ?? 'n/a'),
        ));
    }

    private function mapJoinType(string $type): JoinType
    {
        return match (strtoupper(trim($type))) {
            'INNER' => JoinType::Inner,
            'LEFT' => JoinType::Left,
            'RIGHT' => JoinType::Right,
            'FULL', 'FULL OUTER' => JoinType::FullOuter,
            'CROSS' => JoinType::Cross,
            'LEFT LATERAL' => JoinType::LeftLateral,
            default => throw new \RuntimeException(sprintf('Join type no soportado [%s].', $type)),
        };
    }

    private function mapOrderDirection(string $direction): Order
    {
        return match (strtoupper(trim($direction))) {
            'ASC' => Order::Asc,
            'DESC' => Order::Desc,
            default => throw new \RuntimeException(sprintf('Direction no soportada [%s].', $direction)),
        };
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
