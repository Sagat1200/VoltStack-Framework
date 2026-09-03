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
    /**
     * @var array<string, array{top_limit:int,top_dimension:string,top_order:string,top_format:string}>
     */
    private const TOP_PRESETS = [
        'ops' => [
            'top_limit' => 5,
            'top_dimension' => 'logical_target',
            'top_order' => 'operations',
            'top_format' => 'table',
        ],
        'noise' => [
            'top_limit' => 10,
            'top_dimension' => 'all',
            'top_order' => 'suppressed',
            'top_format' => 'compact',
        ],
        'hotspots' => [
            'top_limit' => 5,
            'top_dimension' => 'fingerprint',
            'top_order' => 'visible',
            'top_format' => 'table',
        ],
    ];

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
        return 'db:health [--json] [--aggregate] [--limit=50] [--top-preset=ops|noise|hotspots] [--top-limit=3] [--top-dimension=all|fingerprint|logical_target] [--top-alert=name] [--top-order=suppressed|visible|operations] [--top-format=compact|table|json-lines]';
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
            '--top-preset=' => 'Aplica presets operativos para offenders: ops, noise o hotspots. Los flags explícitos prevalecen.',
            '--top-limit=' => 'Cantidad máxima de top offenders a imprimir por dimensión en la salida humana.',
            '--top-dimension=' => 'Filtra la salida humana de offenders por dimensión: all, fingerprint o logical_target.',
            '--top-alert=' => 'Filtra offenders que contengan una alerta específica en su resumen agregado.',
            '--top-order=' => 'Ordena la salida humana de offenders por suppressed, visible u operations.',
            '--top-format=' => 'Selecciona el formato de salida humana para offenders: compact, table o json-lines.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $app = $this->bootstrapApplication();

        try {
            /** @var DatabaseHealthStoreInterface $store */
            $store = $app->make(DatabaseHealthStoreInterface::class);
            $limit = $this->resolvePositiveIntOption($input, 'limit') ?? 50;
            $topPreset = $this->resolveTopPresetOption($input);
            if ($topPreset === false) {
                $output->error('db:health failed: --top-preset debe ser ops, noise o hotspots.');
                return 1;
            }

            $topDefaults = $this->resolveTopPresetDefaults(is_string($topPreset) ? $topPreset : null);
            $topLimit = $this->resolvePositiveIntOption($input, 'top-limit') ?? $topDefaults['top_limit'];
            $topDimension = $this->resolveTopDimensionOption($input, $topDefaults['top_dimension']);
            if ($topDimension === null) {
                $output->error('db:health failed: --top-dimension debe ser all, fingerprint o logical_target.');
                return 1;
            }
            $topAlert = $this->resolveOptionalStringOption($input, 'top-alert');
            $topOrder = $this->resolveTopOrderOption($input, $topDefaults['top_order']);
            if ($topOrder === null) {
                $output->error('db:health failed: --top-order debe ser suppressed, visible u operations.');
                return 1;
            }
            $topFormat = $this->resolveTopFormatOption($input, $topDefaults['top_format']);
            if ($topFormat === null) {
                $output->error('db:health failed: --top-format debe ser compact, table o json-lines.');
                return 1;
            }

            if ($input->hasOption('aggregate')) {
                $aggregate = $store->aggregate($limit);

                if ($input->hasOption('json')) {
                    $aggregate = $this->attachAggregateViewHints(
                        $aggregate,
                        is_string($topPreset) ? $topPreset : null,
                        $topDefaults,
                    );
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
                    'Remote replay challenge: observed=%d verified=%d unavailable=%d rejected=%d compatible=%d incompatible=%d reused_receipts=%d cleanup_tombstones=%d protocols=%s request_key_ids=%s response_key_ids=%s',
                    (int) ($remoteReplayChallenge['observed_operations'] ?? 0),
                    (int) ($remoteReplayChallenge['verified'] ?? 0),
                    (int) ($remoteReplayChallenge['unavailable'] ?? 0),
                    (int) ($remoteReplayChallenge['rejected'] ?? 0),
                    (int) ($remoteReplayChallenge['compatible'] ?? 0),
                    (int) ($remoteReplayChallenge['incompatible'] ?? 0),
                    (int) ($remoteReplayChallenge['reused_receipts'] ?? 0),
                    (int) ($remoteReplayChallenge['cleanup_tombstones'] ?? 0),
                    $this->formatCountMap(is_array($remoteReplayChallenge['protocols'] ?? null) ? $remoteReplayChallenge['protocols'] : []),
                    $this->formatCountMap(is_array($remoteReplayChallenge['request_key_ids'] ?? null) ? $remoteReplayChallenge['request_key_ids'] : []),
                    $this->formatCountMap(is_array($remoteReplayChallenge['response_key_ids'] ?? null) ? $remoteReplayChallenge['response_key_ids'] : []),
                ));
                $this->renderResourceGovernanceSummary($summary, $output, true);
                $this->renderAlertSamplingTopOffenders($summary, $output, $topLimit, $topDimension, $topAlert, $topOrder, $topFormat);

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
                'Remote replay challenge: observed=%d verified=%d unavailable=%d rejected=%d compatible=%d incompatible=%d reused_receipts=%d cleanup_tombstones=%d protocols=%s request_key_ids=%s response_key_ids=%s',
                (int) ($remoteReplayChallenge['observed_operations'] ?? 0),
                (int) ($remoteReplayChallenge['verified'] ?? 0),
                (int) ($remoteReplayChallenge['unavailable'] ?? 0),
                (int) ($remoteReplayChallenge['rejected'] ?? 0),
                (int) ($remoteReplayChallenge['compatible'] ?? 0),
                (int) ($remoteReplayChallenge['incompatible'] ?? 0),
                (int) ($remoteReplayChallenge['reused_receipts'] ?? 0),
                (int) ($remoteReplayChallenge['cleanup_tombstones'] ?? 0),
                $this->formatCountMap(is_array($remoteReplayChallenge['protocols'] ?? null) ? $remoteReplayChallenge['protocols'] : []),
                $this->formatCountMap(is_array($remoteReplayChallenge['request_key_ids'] ?? null) ? $remoteReplayChallenge['request_key_ids'] : []),
                $this->formatCountMap(is_array($remoteReplayChallenge['response_key_ids'] ?? null) ? $remoteReplayChallenge['response_key_ids'] : []),
            ));
            $this->renderResourceGovernanceSummary($summary, $output, false);
            $this->renderAlertSamplingTopOffenders($summary, $output, $topLimit, $topDimension, $topAlert, $topOrder, $topFormat);

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

    private function resolveOptionalStringOption(Input $input, string $option): ?string
    {
        $value = $input->option($option);
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveTopPresetOption(Input $input): string|false|null
    {
        $value = $this->resolveOptionalStringOption($input, 'top-preset');
        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);

        return array_key_exists($normalized, self::TOP_PRESETS)
            ? $normalized
            : false;
    }

    /**
     * @return array{top_limit:int,top_dimension:string,top_order:string,top_format:string}
     */
    private function resolveTopPresetDefaults(?string $preset): array
    {
        return $preset !== null && array_key_exists($preset, self::TOP_PRESETS)
            ? self::TOP_PRESETS[$preset]
            : [
                'top_limit' => 3,
                'top_dimension' => 'all',
                'top_order' => 'suppressed',
                'top_format' => 'compact',
            ];
    }

    private function resolveTopDimensionOption(Input $input, string $default = 'all'): ?string
    {
        $value = strtolower($this->resolveOptionalStringOption($input, 'top-dimension') ?? $default);

        return in_array($value, ['all', 'fingerprint', 'logical_target'], true)
            ? $value
            : null;
    }

    private function resolveTopOrderOption(Input $input, string $default = 'suppressed'): ?string
    {
        $value = strtolower($this->resolveOptionalStringOption($input, 'top-order') ?? $default);

        return in_array($value, ['suppressed', 'visible', 'operations'], true)
            ? $value
            : null;
    }

    private function resolveTopFormatOption(Input $input, string $default = 'compact'): ?string
    {
        $value = strtolower($this->resolveOptionalStringOption($input, 'top-format') ?? $default);

        return in_array($value, ['compact', 'table', 'json-lines'], true)
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $aggregate
     * @param array{top_limit:int,top_dimension:string,top_order:string,top_format:string} $topDefaults
     * @return array<string, mixed>
     */
    private function attachAggregateViewHints(array $aggregate, ?string $selectedPreset, array $topDefaults): array
    {
        $summary = is_array($aggregate['summary'] ?? null) ? $aggregate['summary'] : [];
        $summaryHints = $this->aggregateSummaryViewHints($summary);
        $aggregate['view_hints'] = [
            'summary' => $summaryHints,
            'top_offenders' => [
                'presets' => $this->topPresetViewHints(),
                'recommended_presets' => $this->recommendedTopPresets($summary, $summaryHints),
                'effective_defaults' => [
                    'preset' => $selectedPreset ?? 'custom',
                    'top_limit' => $topDefaults['top_limit'],
                    'top_dimension' => $topDefaults['top_dimension'],
                    'top_order' => $topDefaults['top_order'],
                    'top_format' => $topDefaults['top_format'],
                ],
            ],
        ];

        return $aggregate;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function aggregateSummaryViewHints(array $summary): array
    {
        $alertSampling = is_array($summary['alert_sampling'] ?? null) ? $summary['alert_sampling'] : [];
        $byFingerprint = is_array($alertSampling['by_fingerprint'] ?? null) ? $alertSampling['by_fingerprint'] : [];
        $byLogicalTarget = is_array($alertSampling['by_logical_target'] ?? null) ? $alertSampling['by_logical_target'] : [];
        $remoteReplay = is_array($summary['remote_replay_challenge'] ?? null) ? $summary['remote_replay_challenge'] : [];

        $suppressedTotal = $this->sumAlertSamplingMetric($byFingerprint, 'suppressed_total');
        $visibleTotal = $this->sumAlertSamplingMetric($byFingerprint, 'visible_total');
        $logicalTargetGroups = $this->countStructuredGroups($byLogicalTarget);

        return [
            'noise_detected' => $suppressedTotal > 0,
            'hotspot_detected' => $visibleTotal > 0,
            'ops_spread_detected' => $logicalTargetGroups > 1,
            'slow_queries_detected' => (int) ($summary['slow_queries'] ?? 0) > 0,
            'failure_pressure_detected' => (int) ($summary['failed'] ?? 0) > 0,
            'resource_pressure_detected' => $this->resourcePressureDetected($summary),
            'tenant_pressure_detected' => $this->tenantPressureDetected($summary),
            'remote_replay_activity_detected' => (int) ($remoteReplay['observed_operations'] ?? 0) > 0,
            'remote_replay_cleanup_detected' => (int) ($remoteReplay['cleanup_tombstones'] ?? 0) > 0,
            'signal_coverage' => [
                'alert_sampling_detail' => $this->countStructuredGroups($byFingerprint) > 0 || $logicalTargetGroups > 0,
                'remote_replay_summary' => $remoteReplay !== [],
                'resource_governance_summary' => is_array($summary['resource_governance'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string, array<string, scalar>>
     */
    private function topPresetViewHints(): array
    {
        $descriptions = [
            'ops' => 'Prioriza logical_target por volumen operativo para revisión rápida por dominio.',
            'noise' => 'Prioriza supresión agregada para detectar saturación de alertas o ruido repetitivo.',
            'hotspots' => 'Prioriza fingerprint por visibilidad para identificar consultas SQG más expuestas.',
        ];

        $hints = [];
        foreach (self::TOP_PRESETS as $name => $preset) {
            $hints[$name] = [
                'description' => $descriptions[$name] ?? '',
                'top_limit' => $preset['top_limit'],
                'top_dimension' => $preset['top_dimension'],
                'top_order' => $preset['top_order'],
                'top_format' => $preset['top_format'],
            ];
        }

        return $hints;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $summaryHints
     * @return list<array{name:string,reason:string,score:int,priority:string}>
     */
    private function recommendedTopPresets(array $summary, ?array $summaryHints = null): array
    {
        $summaryHints ??= $this->aggregateSummaryViewHints($summary);
        $signalCoverage = is_array($summaryHints['signal_coverage'] ?? null)
            ? $summaryHints['signal_coverage']
            : [];

        /** @var array<string, array{name:string,reason:string,score:int,priority:string}> $recommended */
        $recommended = [];

        if (($summaryHints['ops_spread_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'ops',
                'Hay actividad distribuida por logical_target y conviene priorizar volumen operativo.',
                80,
            );
        }

        if (($summaryHints['noise_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'noise',
                'Se observan alertas suprimidas y conviene revisar concentraciones de ruido.',
                90,
            );
        }

        if (($summaryHints['hotspot_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'hotspots',
                'Hay alertas visibles y conviene priorizar fingerprints con mayor exposición.',
                90,
            );
        }

        if (($summaryHints['failure_pressure_detected'] ?? false) === true || ($summaryHints['slow_queries_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'hotspots',
                'Se observan fallos o lentitud y conviene priorizar fingerprints para diagnóstico puntual.',
                75,
            );
        }

        if (($summaryHints['resource_pressure_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'hotspots',
                'Se detecta presión transversal de recursos y conviene revisar operaciones y fingerprints dominantes.',
                80,
            );
        }

        if (($summaryHints['tenant_pressure_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'ops',
                'Hay presión de recursos concentrada por tenant y conviene revisar la distribución operativa.',
                70,
            );
        }

        if (($summaryHints['remote_replay_activity_detected'] ?? false) === true || ($summaryHints['remote_replay_cleanup_detected'] ?? false) === true) {
            $this->addRecommendedPresetSignal(
                $recommended,
                'ops',
                'Hay actividad operativa de remote replay y conviene revisar logical_target y coordinación entre nodos.',
                85,
            );
        }

        $recommended = $this->sortRecommendedPresets(array_values($recommended));

        if ($recommended === []) {
            $recommended[] = $this->createRecommendedPreset(
                'noise',
                (($signalCoverage['alert_sampling_detail'] ?? false) === true)
                    ? 'No hubo una señal dominante; se recomienda una vista general centrada en supresión.'
                    : 'No hay suficiente detalle agregado de alert_sampling; se recomienda una vista general centrada en supresión.',
                (($signalCoverage['alert_sampling_detail'] ?? false) === true) ? 30 : 20,
            );
        }

        return $recommended;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function resourcePressureDetected(array $summary): bool
    {
        $resourceGovernance = is_array($summary['resource_governance'] ?? null) ? $summary['resource_governance'] : [];
        $pressure = is_array($resourceGovernance['pressure'] ?? null) ? $resourceGovernance['pressure'] : [];

        return ((bool) ($pressure['timeout_pressure_detected'] ?? false))
            || ((bool) ($pressure['row_pressure_detected'] ?? false))
            || ((bool) ($pressure['depth_pressure_detected'] ?? false))
            || ((int) ($resourceGovernance['resource_exhausted_operations'] ?? 0) > 0);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function tenantPressureDetected(array $summary): bool
    {
        $resourceGovernance = is_array($summary['resource_governance'] ?? null) ? $summary['resource_governance'] : [];
        $byTenant = is_array($resourceGovernance['by_tenant'] ?? null) ? $resourceGovernance['by_tenant'] : [];

        foreach ($byTenant as $tenantSummary) {
            if (!is_array($tenantSummary)) {
                continue;
            }

            $pressure = is_array($tenantSummary['pressure'] ?? null) ? $tenantSummary['pressure'] : [];
            if (
                ((bool) ($pressure['timeout_pressure_detected'] ?? false))
                || ((bool) ($pressure['row_pressure_detected'] ?? false))
                || ((bool) ($pressure['depth_pressure_detected'] ?? false))
                || ((int) ($tenantSummary['resource_exhausted_operations'] ?? 0) > 0)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{name:string,reason:string,score:int,priority:string}> $recommended
     */
    private function addRecommendedPresetSignal(array &$recommended, string $name, string $reason, int $score): void
    {
        if (!isset($recommended[$name])) {
            $recommended[$name] = $this->createRecommendedPreset($name, $reason, $score);
            return;
        }

        $existing = $recommended[$name];
        $existing['score'] += $score;
        $existing['priority'] = $this->priorityForRecommendationScore($existing['score']);

        if (!str_contains($existing['reason'], $reason)) {
            $existing['reason'] .= ' ' . $reason;
        }

        $recommended[$name] = $existing;
    }

    /**
     * @return array{name:string,reason:string,score:int,priority:string}
     */
    private function createRecommendedPreset(string $name, string $reason, int $score): array
    {
        return [
            'name' => $name,
            'reason' => $reason,
            'score' => $score,
            'priority' => $this->priorityForRecommendationScore($score),
        ];
    }

    private function priorityForRecommendationScore(int $score): string
    {
        return match (true) {
            $score >= 120 => 'high',
            $score >= 60 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param list<array{name:string,reason:string,score:int,priority:string}> $recommended
     * @return list<array{name:string,reason:string,score:int,priority:string}>
     */
    private function sortRecommendedPresets(array $recommended): array
    {
        usort($recommended, static function (array $left, array $right): int {
            $scoreComparison = ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $recommended;
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function sumAlertSamplingMetric(array $groups, string $field): int
    {
        $total = 0;
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $total += (int) ($group[$field] ?? 0);
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function countStructuredGroups(array $groups): int
    {
        $count = 0;
        foreach ($groups as $group) {
            if (is_array($group)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderResourceGovernanceSummary(array $summary, Output $output, bool $aggregate): void
    {
        $resourceGovernance = is_array($summary['resource_governance'] ?? null) ? $summary['resource_governance'] : [];
        if ($resourceGovernance === []) {
            return;
        }

        $pressure = is_array($resourceGovernance['pressure'] ?? null) ? $resourceGovernance['pressure'] : [];
        $tenantCount = is_array($resourceGovernance['by_tenant'] ?? null) ? count($resourceGovernance['by_tenant']) : 0;

        $prefix = $aggregate ? 'Resource governance aggregate' : 'Resource governance';
        $output->writeln(sprintf(
            '%s: requests=%d operations=%d duration_ms=%d rows_read=%d affected_rows=%d exhausted=%d near_timeout=%d near_rows=%d near_depth=%d timeout_pressure=%s row_pressure=%s depth_pressure=%s tenant_scopes=%d',
            $prefix,
            $aggregate ? (int) ($resourceGovernance['observed_requests'] ?? 0) : 1,
            (int) ($resourceGovernance['observed_operations'] ?? 0),
            (int) ($resourceGovernance['duration_ms_total'] ?? 0),
            (int) ($resourceGovernance['rows_read_total'] ?? 0),
            (int) ($resourceGovernance['affected_rows_total'] ?? 0),
            (int) ($resourceGovernance['resource_exhausted_operations'] ?? 0),
            (int) ($pressure['near_timeout_operations'] ?? 0),
            (int) ($pressure['near_row_limit_operations'] ?? 0),
            (int) ($pressure['near_depth_limit_operations'] ?? 0),
            ((bool) ($pressure['timeout_pressure_detected'] ?? false)) ? 'yes' : 'no',
            ((bool) ($pressure['row_pressure_detected'] ?? false)) ? 'yes' : 'no',
            ((bool) ($pressure['depth_pressure_detected'] ?? false)) ? 'yes' : 'no',
            $tenantCount,
        ));

        if (!$aggregate || $tenantCount === 0) {
            return;
        }

        $this->renderResourceGovernanceByTenant($resourceGovernance, $output);
    }

    /**
     * @param array<string, mixed> $resourceGovernance
     */
    private function renderResourceGovernanceByTenant(array $resourceGovernance, Output $output): void
    {
        $byTenant = is_array($resourceGovernance['by_tenant'] ?? null) ? $resourceGovernance['by_tenant'] : [];
        if ($byTenant === []) {
            return;
        }

        uasort($byTenant, static function (mixed $left, mixed $right): int {
            $leftArray = is_array($left) ? $left : [];
            $rightArray = is_array($right) ? $right : [];
            $leftPressure = is_array($leftArray['pressure'] ?? null) ? $leftArray['pressure'] : [];
            $rightPressure = is_array($rightArray['pressure'] ?? null) ? $rightArray['pressure'] : [];

            foreach (['resource_exhausted_operations', 'duration_ms_total', 'rows_read_total'] as $field) {
                $comparison = ((int) ($rightArray[$field] ?? 0)) <=> ((int) ($leftArray[$field] ?? 0));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            foreach (['near_timeout_operations', 'near_row_limit_operations', 'near_depth_limit_operations'] as $field) {
                $comparison = ((int) ($rightPressure[$field] ?? 0)) <=> ((int) ($leftPressure[$field] ?? 0));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        $output->writeln('Resource governance by tenant:');
        foreach (array_slice($byTenant, 0, 5, true) as $tenantId => $tenantSummary) {
            if (!is_array($tenantSummary)) {
                continue;
            }

            $pressure = is_array($tenantSummary['pressure'] ?? null) ? $tenantSummary['pressure'] : [];
            $output->writeln(sprintf(
                '  tenant=%s requests=%d operations=%d duration_ms=%d exhausted=%d near_timeout=%d near_rows=%d near_depth=%d',
                trim((string) $tenantId) !== '' ? (string) $tenantId : 'n/a',
                (int) ($tenantSummary['requests'] ?? 0),
                (int) ($tenantSummary['observed_operations'] ?? 0),
                (int) ($tenantSummary['duration_ms_total'] ?? 0),
                (int) ($tenantSummary['resource_exhausted_operations'] ?? 0),
                (int) ($pressure['near_timeout_operations'] ?? 0),
                (int) ($pressure['near_row_limit_operations'] ?? 0),
                (int) ($pressure['near_depth_limit_operations'] ?? 0),
            ));
        }
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
        $receiptTombstone = is_array($entry['challenge_receipt_tombstone_advertisement'] ?? null)
            ? $entry['challenge_receipt_tombstone_advertisement']
            : null;

        if (
            $status === ''
            && $protocol === ''
            && $compatibility === ''
            && $requestKeyId === ''
            && $responseKeyId === ''
            && $receiptReuse === ''
            && $receiptTombstone === null
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

        if ($receiptTombstone !== null) {
            $formatted .= sprintf(
                ' receipt_tombstone=%s tombstone_source=%s tombstone_at=%s',
                trim((string) ($receiptTombstone['reason'] ?? '')) !== '' ? (string) $receiptTombstone['reason'] : 'n/a',
                trim((string) ($receiptTombstone['source_node_id'] ?? '')) !== '' ? (string) $receiptTombstone['source_node_id'] : 'n/a',
                trim((string) ($receiptTombstone['pruned_at'] ?? '')) !== '' ? (string) $receiptTombstone['pruned_at'] : 'n/a',
            );
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderAlertSamplingTopOffenders(
        array $summary,
        Output $output,
        int $topLimit,
        string $topDimension,
        ?string $topAlert,
        string $topOrder,
        string $topFormat,
    ): void {
        $alertSampling = is_array($summary['alert_sampling'] ?? null) ? $summary['alert_sampling'] : [];
        $topOffenders = is_array($alertSampling['top_offenders'] ?? null) ? $alertSampling['top_offenders'] : [];
        $byFingerprint = is_array($topOffenders['by_fingerprint'] ?? null) ? $topOffenders['by_fingerprint'] : [];
        $byLogicalTarget = is_array($topOffenders['by_logical_target'] ?? null) ? $topOffenders['by_logical_target'] : [];
        $groupedByFingerprint = is_array($alertSampling['by_fingerprint'] ?? null) ? $alertSampling['by_fingerprint'] : [];
        $groupedByLogicalTarget = is_array($alertSampling['by_logical_target'] ?? null) ? $alertSampling['by_logical_target'] : [];

        if ($topDimension !== 'logical_target') {
            $byFingerprint = $this->filterTopOffendersByAlert($byFingerprint, 'fingerprint', $groupedByFingerprint, $topAlert);
            $byFingerprint = $this->sortTopOffenders($byFingerprint, 'fingerprint', $topOrder);
        } else {
            $byFingerprint = [];
        }

        if ($topDimension !== 'fingerprint') {
            $byLogicalTarget = $this->filterTopOffendersByAlert($byLogicalTarget, 'logical_target', $groupedByLogicalTarget, $topAlert);
            $byLogicalTarget = $this->sortTopOffenders($byLogicalTarget, 'logical_target', $topOrder);
        } else {
            $byLogicalTarget = [];
        }

        if ($byFingerprint === [] && $byLogicalTarget === []) {
            return;
        }

        $output->writeln(sprintf(
            'Alert sampling top offenders: fingerprints=%d logical_targets=%d',
            count($byFingerprint),
            count($byLogicalTarget),
        ));

        $this->renderTopOffenderList('fingerprint', $byFingerprint, $output, $topLimit, $topFormat);
        $this->renderTopOffenderList('logical_target', $byLogicalTarget, $output, $topLimit, $topFormat);
    }

    /**
     * @param list<array<string, mixed>> $offenders
     * @param array<string, mixed> $groups
     * @return list<array<string, mixed>>
     */
    private function filterTopOffendersByAlert(array $offenders, string $label, array $groups, ?string $topAlert): array
    {
        if ($topAlert === null) {
            return $offenders;
        }

        $filtered = [];
        foreach ($offenders as $offender) {
            if (!is_array($offender)) {
                continue;
            }

            $groupKey = isset($offender[$label]) ? trim((string) $offender[$label]) : '';
            if ($groupKey === '') {
                continue;
            }

            $topAlertName = isset($offender['top_alert_name']) ? trim((string) $offender['top_alert_name']) : '';
            $group = is_array($groups[$groupKey] ?? null) ? $groups[$groupKey] : [];
            $alerts = is_array($group['alerts'] ?? null) ? $group['alerts'] : [];

            if ($topAlertName === $topAlert || array_key_exists($topAlert, $alerts)) {
                $filtered[] = $offender;
            }
        }

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $offenders
     * @return list<array<string, mixed>>
     */
    private function sortTopOffenders(array $offenders, string $label, string $topOrder): array
    {
        usort($offenders, function (array $left, array $right) use ($label, $topOrder): int {
            return match ($topOrder) {
                'visible' => $this->compareTopOffenders($left, $right, ['visible_total', 'suppressed_total', 'not_promoted_total', 'operations'], $label),
                'operations' => $this->compareTopOffenders($left, $right, ['operations', 'suppressed_total', 'visible_total', 'not_promoted_total'], $label),
                default => $this->compareTopOffenders($left, $right, ['suppressed_total', 'visible_total', 'not_promoted_total', 'operations'], $label),
            };
        });

        return array_values($offenders);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @param list<string> $priority
     */
    private function compareTopOffenders(array $left, array $right, array $priority, string $label): int
    {
        foreach ($priority as $field) {
            $comparison = ((int) ($right[$field] ?? 0)) <=> ((int) ($left[$field] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return strcmp(
            isset($left[$label]) ? trim((string) $left[$label]) : '',
            isset($right[$label]) ? trim((string) $right[$label]) : '',
        );
    }

    /**
     * @param list<array<string, mixed>> $offenders
     */
    private function renderTopOffenderList(
        string $label,
        array $offenders,
        Output $output,
        int $limit = 3,
        string $format = 'compact',
    ): void {
        if ($offenders === []) {
            return;
        }

        $slice = array_slice($offenders, 0, max(1, $limit));
        if ($format === 'table') {
            $this->renderTopOffenderTable($label, $slice, $output);
            return;
        }

        if ($format === 'json-lines') {
            $this->renderTopOffenderJsonLines($label, $slice, $output);
            return;
        }

        foreach ($slice as $offender) {
            if (!is_array($offender)) {
                continue;
            }

            $groupValue = isset($offender[$label]) ? trim((string) $offender[$label]) : '';
            $topAlertName = isset($offender['top_alert_name']) ? trim((string) $offender['top_alert_name']) : '';

            $output->writeln(sprintf(
                '  %s=%s operations=%d suppressed=%d visible=%d not_promoted=%d top_alert=%s',
                $label,
                $groupValue !== '' ? $groupValue : 'n/a',
                (int) ($offender['operations'] ?? 0),
                (int) ($offender['suppressed_total'] ?? 0),
                (int) ($offender['visible_total'] ?? 0),
                (int) ($offender['not_promoted_total'] ?? 0),
                $topAlertName !== '' ? $topAlertName : 'n/a',
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $offenders
     */
    private function renderTopOffenderTable(string $label, array $offenders, Output $output): void
    {
        $output->writeln(sprintf('  [%s]', $label));
        $output->writeln('    group | operations | suppressed | visible | not_promoted | top_alert');

        foreach ($offenders as $offender) {
            if (!is_array($offender)) {
                continue;
            }

            $groupValue = isset($offender[$label]) ? trim((string) $offender[$label]) : '';
            $topAlertName = isset($offender['top_alert_name']) ? trim((string) $offender['top_alert_name']) : '';

            $output->writeln(sprintf(
                '    %s | %d | %d | %d | %d | %s',
                $groupValue !== '' ? $groupValue : 'n/a',
                (int) ($offender['operations'] ?? 0),
                (int) ($offender['suppressed_total'] ?? 0),
                (int) ($offender['visible_total'] ?? 0),
                (int) ($offender['not_promoted_total'] ?? 0),
                $topAlertName !== '' ? $topAlertName : 'n/a',
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $offenders
     */
    private function renderTopOffenderJsonLines(string $label, array $offenders, Output $output): void
    {
        foreach ($offenders as $offender) {
            if (!is_array($offender)) {
                continue;
            }

            $groupValue = isset($offender[$label]) ? trim((string) $offender[$label]) : '';
            $topAlertName = isset($offender['top_alert_name']) ? trim((string) $offender['top_alert_name']) : '';

            $output->writeln((string) json_encode([
                'dimension' => $label,
                'group' => $groupValue !== '' ? $groupValue : null,
                'operations' => (int) ($offender['operations'] ?? 0),
                'suppressed_total' => (int) ($offender['suppressed_total'] ?? 0),
                'visible_total' => (int) ($offender['visible_total'] ?? 0),
                'not_promoted_total' => (int) ($offender['not_promoted_total'] ?? 0),
                'top_alert_name' => $topAlertName !== '' ? $topAlertName : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
