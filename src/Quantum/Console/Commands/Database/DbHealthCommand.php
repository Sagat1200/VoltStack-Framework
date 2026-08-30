<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class DbHealthCommand extends Command
{
    public function name(): string
    {
        return 'db:health';
    }

    public function description(): string
    {
        return 'Muestra el último snapshot persistido de salud y telemetría Database.';
    }

    public function usage(): string
    {
        return 'db:health [--json] [--aggregate] [--limit=50]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Imprime el snapshot persistido en JSON.',
            '--aggregate' => 'Muestra una vista agregada de snapshots recientes.',
            '--limit=' => 'Cantidad máxima de snapshots a considerar en la agregación.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();

        try {
            /** @var DatabaseHealthStoreInterface $store */
            $store = $app->make(DatabaseHealthStoreInterface::class);
            $limit = $this->resolvePositiveIntOption($input, 'limit') ?? 50;

            if ($input->hasOption('aggregate')) {
                $aggregate = $store->aggregate($limit);

                if ($input->hasOption('json')) {
                    $output->writeln((string) json_encode(
                        $aggregate,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ));
                    return 0;
                }

                $output->writeln(sprintf(
                    'Database health aggregate: snapshots=%d requests=%d tenants=%d nodes=%d segments=%d window=%s..%s',
                    (int) ($aggregate['snapshots'] ?? 0),
                    (int) ($aggregate['requests'] ?? 0),
                    (int) ($aggregate['tenants'] ?? 0),
                    (int) ($aggregate['nodes'] ?? 0),
                    (int) ($aggregate['observed_segments'] ?? 0),
                    (string) ($aggregate['oldest_generated_at'] ?? 'n/a'),
                    (string) ($aggregate['latest_generated_at'] ?? 'n/a'),
                ));

                $summary = is_array($aggregate['summary'] ?? null) ? $aggregate['summary'] : [];
                $health = is_array($aggregate['health'] ?? null) ? $aggregate['health'] : [];
                $remoteReplayChallenge = is_array($summary['remote_replay_challenge'] ?? null)
                    ? $summary['remote_replay_challenge']
                    : [];

                $output->writeln(sprintf(
                    'Summary: total=%d completed=%d failed=%d cancelled=%d slow=%d',
                    (int) ($summary['total_operations'] ?? 0),
                    (int) ($summary['completed'] ?? 0),
                    (int) ($summary['failed'] ?? 0),
                    (int) ($summary['cancelled'] ?? 0),
                    (int) ($summary['slow_queries'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Health: closed=%d half_open=%d open=%d',
                    (int) ($health['closed_segments'] ?? 0),
                    (int) ($health['half_open_segments'] ?? 0),
                    (int) ($health['open_segments'] ?? 0),
                ));
                $output->writeln(sprintf(
                    'Remote replay challenge: observed=%d verified=%d unavailable=%d rejected=%d compatible=%d incompatible=%d reused_receipts=%d protocols=%s request_key_ids=%s response_key_ids=%s',
                    (int) ($remoteReplayChallenge['observed_operations'] ?? 0),
                    (int) ($remoteReplayChallenge['verified'] ?? 0),
                    (int) ($remoteReplayChallenge['unavailable'] ?? 0),
                    (int) ($remoteReplayChallenge['rejected'] ?? 0),
                    (int) ($remoteReplayChallenge['compatible'] ?? 0),
                    (int) ($remoteReplayChallenge['incompatible'] ?? 0),
                    (int) ($remoteReplayChallenge['reused_receipts'] ?? 0),
                    $this->formatCountMap(is_array($remoteReplayChallenge['protocols'] ?? null) ? $remoteReplayChallenge['protocols'] : []),
                    $this->formatCountMap(is_array($remoteReplayChallenge['request_key_ids'] ?? null) ? $remoteReplayChallenge['request_key_ids'] : []),
                    $this->formatCountMap(is_array($remoteReplayChallenge['response_key_ids'] ?? null) ? $remoteReplayChallenge['response_key_ids'] : []),
                ));

                return 0;
            }

            $report = $store->latest();

            if (!$report instanceof DatabaseTelemetryReport) {
                $output->writeln('No hay health snapshot persistido.');
                return 0;
            }

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode(
                    $report->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
                return 0;
            }

            $summary = $report->summary;
            $health = $report->health;
            $remoteReplayChallenge = is_array($summary['remote_replay_challenge'] ?? null)
                ? $summary['remote_replay_challenge']
                : [];

            $output->writeln(sprintf(
                'Database health: request=%s tenant=%s trace=%s generated_at=%s',
                $report->requestId,
                $report->tenantId ?? 'n/a',
                $report->traceId ?? 'n/a',
                $report->generatedAt,
            ));
            $output->writeln(sprintf('Node: %s', $report->nodeId ?? 'n/a'));
            $output->writeln(sprintf(
                'Summary: total=%d completed=%d failed=%d cancelled=%d slow=%d',
                (int) ($summary['total_operations'] ?? 0),
                (int) ($summary['completed'] ?? 0),
                (int) ($summary['failed'] ?? 0),
                (int) ($summary['cancelled'] ?? 0),
                (int) ($summary['slow_queries'] ?? 0),
            ));
            $output->writeln(sprintf(
                'Health: segments=%d closed=%d half_open=%d open=%d',
                (int) ($health['total_segments'] ?? 0),
                (int) ($health['closed_segments'] ?? 0),
                (int) ($health['half_open_segments'] ?? 0),
                (int) ($health['open_segments'] ?? 0),
            ));
            $output->writeln(sprintf(
                'Remote replay challenge: observed=%d verified=%d unavailable=%d rejected=%d compatible=%d incompatible=%d reused_receipts=%d protocols=%s request_key_ids=%s response_key_ids=%s',
                (int) ($remoteReplayChallenge['observed_operations'] ?? 0),
                (int) ($remoteReplayChallenge['verified'] ?? 0),
                (int) ($remoteReplayChallenge['unavailable'] ?? 0),
                (int) ($remoteReplayChallenge['rejected'] ?? 0),
                (int) ($remoteReplayChallenge['compatible'] ?? 0),
                (int) ($remoteReplayChallenge['incompatible'] ?? 0),
                (int) ($remoteReplayChallenge['reused_receipts'] ?? 0),
                $this->formatCountMap(is_array($remoteReplayChallenge['protocols'] ?? null) ? $remoteReplayChallenge['protocols'] : []),
                $this->formatCountMap(is_array($remoteReplayChallenge['request_key_ids'] ?? null) ? $remoteReplayChallenge['request_key_ids'] : []),
                $this->formatCountMap(is_array($remoteReplayChallenge['response_key_ids'] ?? null) ? $remoteReplayChallenge['response_key_ids'] : []),
            ));

            $latest = is_array($summary['latest'] ?? null) ? $summary['latest'] : [];
            foreach ($latest as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $remoteReplaySuffix = $this->formatRemoteReplayLatestEntry($entry);
                $output->writeln(sprintf(
                    '  - kind=%s target=%s outcome=%s connection=%s%s',
                    (string) ($entry['operation_kind'] ?? 'n/a'),
                    (string) ($entry['logical_target'] ?? 'n/a'),
                    (string) ($entry['outcome'] ?? 'n/a'),
                    (string) ($entry['connection_name'] ?? 'n/a'),
                    $remoteReplaySuffix,
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:health failed: %s', $e->getMessage()));
            return 1;
        }
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

    /**
     * @param array<string, int> $counts
     */
    private function formatCountMap(array $counts): string
    {
        if ($counts === []) {
            return 'n/a';
        }

        ksort($counts);

        $pairs = [];
        foreach ($counts as $key => $value) {
            $pairs[] = sprintf('%s:%d', $key, (int) $value);
        }

        return implode(',', $pairs);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatRemoteReplayLatestEntry(array $entry): string
    {
        $status = isset($entry['remote_validation_status']) ? trim((string) $entry['remote_validation_status']) : '';
        $protocol = isset($entry['challenge_protocol']) ? trim((string) $entry['challenge_protocol']) : '';
        $compatibility = isset($entry['challenge_compatibility']) ? trim((string) $entry['challenge_compatibility']) : '';
        $requestKeyId = isset($entry['challenge_request_key_id']) ? trim((string) $entry['challenge_request_key_id']) : '';
        $responseKeyId = isset($entry['challenge_response_key_id']) ? trim((string) $entry['challenge_response_key_id']) : '';
        $receiptReuse = isset($entry['challenge_receipt_reuse']) ? trim((string) $entry['challenge_receipt_reuse']) : '';
        $receiptReuseScope = isset($entry['challenge_receipt_reuse_scope']) ? trim((string) $entry['challenge_receipt_reuse_scope']) : '';
        $receiptValidatedByNodeId = isset($entry['challenge_receipt_validated_by_node_id']) ? trim((string) $entry['challenge_receipt_validated_by_node_id']) : '';
        $receiptAttestationVerification = isset($entry['challenge_receipt_attestation_verification']) ? trim((string) $entry['challenge_receipt_attestation_verification']) : '';
        $receiptAttestationKeyId = isset($entry['challenge_receipt_attestation_key_id']) ? trim((string) $entry['challenge_receipt_attestation_key_id']) : '';

        if (
            $status === ''
            && $protocol === ''
            && $compatibility === ''
            && $requestKeyId === ''
            && $responseKeyId === ''
            && $receiptReuse === ''
        ) {
            return '';
        }

        $formatted = sprintf(
            ' rv=%s challenge=%s compat=%s key=%s/%s reuse=%s',
            $status !== '' ? $status : 'n/a',
            $protocol !== '' ? $protocol : 'n/a',
            $compatibility !== '' ? $compatibility : 'n/a',
            $requestKeyId !== '' ? $requestKeyId : 'n/a',
            $responseKeyId !== '' ? $responseKeyId : 'n/a',
            $receiptReuse !== '' ? $receiptReuse : 'n/a',
        );

        if ($receiptReuseScope !== '' || $receiptValidatedByNodeId !== '') {
            $formatted .= sprintf(
                ' reuse_scope=%s validated_by=%s',
                $receiptReuseScope !== '' ? $receiptReuseScope : 'n/a',
                $receiptValidatedByNodeId !== '' ? $receiptValidatedByNodeId : 'n/a',
            );
        }

        if ($receiptAttestationVerification !== '' || $receiptAttestationKeyId !== '') {
            $formatted .= sprintf(
                ' receipt_attestation=%s attestation_key=%s',
                $receiptAttestationVerification !== '' ? $receiptAttestationVerification : 'n/a',
                $receiptAttestationKeyId !== '' ? $receiptAttestationKeyId : 'n/a',
            );
        }

        return $formatted;
    }
}
