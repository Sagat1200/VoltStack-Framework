<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class DbIdempotencyCommand extends Command
{
    public function name(): string
    {
        return 'db:idempotency';
    }

    public function description(): string
    {
        return 'Inspecciona reservas persistidas de idempotencia Database.';
    }

    public function usage(): string
    {
        return 'db:idempotency [--key=raw-key] [--json] [--aggregate] [--limit=50]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--key=' => 'Busca una reserva concreta a partir de la idempotency key original.',
            '--json' => 'Imprime el resultado en JSON.',
            '--aggregate' => 'Muestra una vista agregada de reservas recientes.',
            '--limit=' => 'Cantidad maxima de reservas a considerar en la vista agregada/reciente.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();

        try {
            /** @var DatabaseIdempotencyStoreInterface $store */
            $store = $app->make(DatabaseIdempotencyStoreInterface::class);
            $limit = $this->resolvePositiveIntOption($input, 'limit') ?? 50;

            $lookupKey = $this->resolveLookupKeyHash($input);
            if ($lookupKey !== null) {
                $record = $store->find($lookupKey);

                if (!$record instanceof DatabaseIdempotencyRecord) {
                    $output->writeln('No hay reserva de idempotencia para esa key.');
                    return 0;
                }

                return $this->renderRecord($record, $input, $output);
            }

            if ($input->hasOption('aggregate')) {
                $aggregate = $store->aggregate($limit);

                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode(
                        $aggregate,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ));
                    return 0;
                }

                $statuses = is_array($aggregate['statuses'] ?? null) ? $aggregate['statuses'] : [];
                $output->writeln(sprintf(
                    'Database idempotency aggregate: records=%d requests=%d connections=%d targets=%d nodes=%d window=%s..%s',
                    (int) ($aggregate['records'] ?? 0),
                    (int) ($aggregate['requests'] ?? 0),
                    (int) ($aggregate['connections'] ?? 0),
                    (int) ($aggregate['logical_targets'] ?? 0),
                    (int) ($aggregate['nodes'] ?? 0),
                    (string) ($aggregate['oldest_created_at'] ?? 'n/a'),
                    (string) ($aggregate['latest_created_at'] ?? 'n/a'),
                ));
                $output->writeln(sprintf(
                    'Statuses: pending=%d completed=%d failed=%d expired_pending=%d',
                    (int) ($statuses['pending'] ?? 0),
                    (int) ($statuses['completed'] ?? 0),
                    (int) ($statuses['failed'] ?? 0),
                    (int) ($aggregate['expired_pending'] ?? 0),
                ));

                return 0;
            }

            $record = $store->latest();
            if (!$record instanceof DatabaseIdempotencyRecord) {
                $output->writeln('No hay reservas de idempotencia persistidas.');
                return 0;
            }

            return $this->renderRecord($record, $input, $output);
        } catch (\Throwable $e) {
            $output->error(sprintf('db:idempotency failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function renderRecord(DatabaseIdempotencyRecord $record, Input $input, Output $output): int
    {
        if ($input->hasOption('json')) {
            $output->writeln((string) json_encode(
                $record->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
            return 0;
        }

        $output->writeln(sprintf(
            'Database idempotency: request=%s status=%s created_at=%s expires_at=%s expired=%s',
            $record->requestId,
            $record->status,
            $record->createdAt,
            $record->expiresAt ?? 'n/a',
            $record->isExpired() ? 'yes' : 'no',
        ));
        $output->writeln(sprintf(
            'Key hash: %s',
            $record->keyHash,
        ));
        $output->writeln(sprintf(
            'Operation: fingerprint=%s connection=%s target=%s node=%s',
            $record->operationFingerprint,
            $record->connectionName,
            $record->logicalTarget,
            $record->nodeId ?? 'n/a',
        ));
        if ($record->confirmation !== []) {
            $output->writeln(sprintf(
                'Confirmation: kind=%s affected_rows=%d rows_read=%d outcome=%s confirmed_at=%s',
                (string) ($record->confirmation['kind'] ?? 'n/a'),
                (int) ($record->confirmation['affected_rows'] ?? 0),
                (int) ($record->confirmation['rows_read'] ?? 0),
                (string) ($record->confirmation['outcome'] ?? 'n/a'),
                (string) ($record->confirmation['confirmed_at'] ?? 'n/a'),
            ));
        }

        return 0;
    }

    private function resolveLookupKeyHash(Input $input): ?string
    {
        $value = $input->option('key');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return hash('sha256', trim($value));
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
}
