<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Exceptions\AuthenticationException;
use Quantum\Auth\Exceptions\AuthenticationRequiredException;
use Quantum\Auth\Exceptions\ThrottleDeniedException;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Database\Execution\ExecutionException;
use Quantum\Database\Execution\ExecutionFailure;
use Quantum\Exceptions\Bridges\CompositeTransportMapper;
use Quantum\Exceptions\Bridges\Console\CliTransportMapper;
use Quantum\Exceptions\Bridges\Http\HttpTransportMapper;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\RecoveryPolicyInterface;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\RecoveryContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Core\ExceptionDescriptorFactory;
use Quantum\Exceptions\Core\PublicErrorProjector;
use Quantum\Exceptions\Bridges\Runtime\ExceptionRuntimeBridge;
use Quantum\Exceptions\Bridges\Runtime\FinalizationOutcome;
use Quantum\Exceptions\Bridges\Runtime\RuntimeOperation;
use Quantum\Exceptions\Diagnostics\ExceptionExplainReport;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Reporting\EventDispatcherExceptionReporter;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Quantum\Exceptions\Reporting\ExceptionReportingPolicy;
use Quantum\Exceptions\Reporting\StructuredErrorLogReporter;
use Quantum\Exceptions\Reporting\TelemetryExceptionReporter;
use Quantum\Exceptions\Mapping\DeterministicSemanticExceptionMapper;
use Quantum\Validation\Exceptions\ValidationException;
use Quantum\View\Exceptions\ViewNotFoundException;
use Quantum\View\Exceptions\ViewRenderException;
use Throwable;
use VoltStack\Framework\Application;

final class ExceptionExplainCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:explain';
    }

    public function description(): string
    {
        return 'Explica con fixtures sinteticos el mapping, transporte y salida publica del subsistema.';
    }

    public function usage(): string
    {
        return 'exceptions:explain [--type=validation] [--transport=json] [--json] [--show-receipts] [--matrix] [--effects]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--type=' => 'Fixture sintetico: validation, auth_required, auth_failed, throttled, not_found, db_retryable, db_default, config_invalid, internal, cancelled.',
            '--transport=' => 'Superficie sintetica: json, html, spa, cli o cli-json.',
            '--json' => 'Emite un payload JSON estable con la explicacion.',
            '--show-receipts' => 'Incluye receipts simulados del pipeline de reporting y bloque recovery en la salida.',
            '--matrix' => 'Expande una matriz cross-bridge (json, html, spa, cli, cli-json) con transport/reporting/recovery por cada superficie.',
            '--effects' => 'Agrupa outcomes por efecto (replay, reconcile, abort), diagnostico del bridge runtime (http/job) con FinalizationOutcome y matriz receipts por scope.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function (Application $app) use ($input, $output): int {
            try {
                $type = $this->stringOption($input, 'type') ?? 'validation';
                $transport = $this->stringOption($input, 'transport') ?? 'json';
                $showReceipts = $input->hasOption('show-receipts');
                $matrix = $input->hasOption('matrix');
                $effects = $input->hasOption('effects');
                $plan = $app->make(ExceptionCompilationPlan::class);
                $fixture = $this->fixture($type, $transport, $plan);
                $context = $fixture['context'];
                $throwable = $fixture['throwable'];

                $failure = $app->make(ExceptionNormalizerInterface::class)->normalize($throwable, $context);
                $mapper = $app->make(SemanticExceptionMapperInterface::class);
                $inspection = $this->inspectMapping($mapper, $failure, $context);
                $descriptor = (new ExceptionDescriptorFactory($plan->policyRevision()))->create(
                    occurrenceId: $this->occurrenceId($type, $transport, $plan),
                    failure: $failure,
                    semantic: $inspection['semantic'],
                    context: $context,
                );
                $publicError = (new PublicErrorProjector())->project($descriptor);
                $transportContext = $fixture['transport_context'];
                $transportPlan = $app->make(TransportMapperInterface::class)->map($descriptor, $transportContext);
                $rendered = $app->make(ExceptionRendererInterface::class)->render($publicError, $transportPlan);

                $reportingDecision = $this->evaluateReportingDecision($app, $descriptor);
                $reportReceipt = $this->runReportingPipeline($app, $descriptor, $type, $transport, $plan);
                $serializedReceipts = $this->serializeReceipts($reportReceipt, $showReceipts || $input->hasOption('json'));
                $receiptsMatrix = $this->buildReceiptsMatrix($serializedReceipts);

                $recoveryDecision = $this->evaluateRecoveryDecision($app, $descriptor, $transportContext);
                $transportDiagnostic = $this->buildTransportDiagnostic($app, $descriptor, $transportContext);
                $streamConfirmation = $this->buildStreamConfirmation(
                    $app,
                    $descriptor,
                    $type,
                    $transport,
                    $plan,
                    $reportReceipt,
                    $showReceipts || $input->hasOption('json'),
                );
                $bridgeMatrix = $matrix
                    ? $this->buildBridgeMatrix($app, $type, $plan, $showReceipts || $input->hasOption('json'), $effects)
                    : [];
                $runtimeDiagnostic = $effects
                    ? $this->buildRuntimeDiagnostic($app, $type, $plan, $showReceipts || $input->hasOption('json'))
                    : null;
                $effectMatrix = $effects
                    ? $this->buildEffectMatrix($descriptor->semantic->code, $bridgeMatrix, $runtimeDiagnostic)
                    : [];
                $receiptsMatrixByScope = $effects
                    ? $this->buildReceiptsMatrixByScope($serializedReceipts, $descriptor->occurrenceId, $bridgeMatrix)
                    : [];

                $report = new ExceptionExplainReport(
                    plan: $plan,
                    fixture: [
                        'type' => $type,
                        'transport' => $transport,
                        'description' => $fixture['description'],
                        'synthetic' => true,
                        'exception_class' => $throwable::class,
                    ],
                    failure: $failure,
                    matchedRule: $inspection['matched_rule'],
                    usedFallback: $inspection['used_fallback'],
                    safeParameters: $inspection['semantic']->safeParameters,
                    publicError: $publicError,
                    transportPlan: $transportPlan,
                    rendered: $rendered,
                    reporterIds: $plan->reporterIds(),
                    ignoredByPolicy: $reportingDecision['ignored_by_policy'] ?? false,
                    reachedLimits: $this->reachedLimits($failure),
                    reporterReceipts: $serializedReceipts,
                    reporterReceiptsMatrix: $receiptsMatrix,
                    reportingDecision: $reportingDecision,
                    recoveryDecision: $recoveryDecision,
                    transportDiagnostic: $transportDiagnostic,
                    bridgeMatrix: $bridgeMatrix,
                    effectMatrix: $effectMatrix,
                    receiptsMatrixByScope: $receiptsMatrixByScope,
                    runtimeDiagnostic: $runtimeDiagnostic,
                    streamConfirmation: $streamConfirmation,
                );
                $payload = [
                    'command' => $this->name(),
                    'report' => $report->toArray(),
                ];
                $exitCode = 0;
            } catch (ExceptionCompilationException|\InvalidArgumentException $exception) {
                $payload = [
                    'command' => $this->name(),
                    'report' => null,
                    'error' => $exception->getMessage(),
                ];
                $exitCode = 1;
            }

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return $exitCode;
            }

            if ($exitCode !== 0) {
                $output->writeln('Exceptions explain:');
                $output->writeln(sprintf('  Error: %s', $payload['error'] ?? 'unknown'));

                return $exitCode;
            }

            /** @var array<string, mixed> $report */
            $report = $payload['report'];
            $mapping = $report['mapping'];
            $transportPlan = $report['transport_plan'];
            $rendered = $report['rendered'];

            $output->writeln('Exceptions explain:');
            $output->writeln(sprintf('  Fixture: %s', $report['fixture']['type']));
            $output->writeln(sprintf('  Transport: %s', $report['fixture']['transport']));
            $output->writeln(sprintf('  Code: %s', $mapping['code']));
            $output->writeln(sprintf(
                '  Matched rule: %s',
                is_array($mapping['matched_rule'] ?? null) ? $mapping['matched_rule']['id'] : 'fallback',
            ));
            $output->writeln(sprintf('  Target: %s', $transportPlan['target']));
            $output->writeln(sprintf('  Status: %s', $transportPlan['status'] ?? '-'));
            $output->writeln(sprintf('  Exit code: %s', $transportPlan['exit_code'] ?? '-'));
            $output->writeln(sprintf('  Media type: %s', $rendered['media_type']));
            $output->writeln('  Body preview:');
            $output->writeln(sprintf('    %s', str_replace(PHP_EOL, PHP_EOL . '    ', (string) $rendered['body_preview'])));

            if ($showReceipts) {
                $output->writeln('  Bridge:');
                $transport = $report['transport'] ?? [];
                $output->writeln(sprintf('    Bridge: %s', $transport['bridge'] ?? 'unavailable'));
                $output->writeln(sprintf('    Kind: %s', $transport['kind'] ?? '-'));
                $output->writeln(sprintf('    Route profile: %s', $transport['route_profile'] ?? '-'));
                if (is_array($transport['http'] ?? null) && $transport['http'] !== []) {
                    $output->writeln(sprintf(
                        '    HTTP target: %s',
                        $transport['http']['target'] ?? '-',
                    ));
                }
                if (is_array($transport['spa'] ?? null) && $transport['spa'] !== []) {
                    $output->writeln(sprintf(
                        '    SPA: version=%s target_scope=%s action=%s',
                        $transport['spa']['version'] ?? '-',
                        $transport['spa']['target_scope'] ?? '-',
                        $transport['spa']['action'] ?? '-',
                    ));
                }
                if (is_array($transport['cli'] ?? null) && $transport['cli'] !== []) {
                    $output->writeln(sprintf(
                        '    CLI: target=%s exit_code=%s',
                        $transport['cli']['target'] ?? '-',
                        $transport['cli']['exit_code'] ?? '-',
                    ));
                }

                $output->writeln('  Reporting:');
                $output->writeln(sprintf(
                    '    Ignored by policy: %s',
                    $report['reporting']['ignored_by_policy'] ? 'yes' : 'no',
                ));
                if (is_array($report['reporting']['decision'] ?? null)) {
                    $output->writeln(sprintf(
                        '    Decision: %s (reasons: %s)',
                        $report['reporting']['decision']['should_report'] ? 'allow' : 'skip',
                        implode(', ', (array) ($report['reporting']['decision']['reasons'] ?? ['none'])),
                    ));
                }
                $output->writeln('    Reporting receipts:');
                if ($report['reporting']['receipts'] === []) {
                    $output->writeln('      (none)');
                } else {
                    foreach ($report['reporting']['receipts'] as $receipt) {
                        $parts = [
                            sprintf('reporter=%s', $receipt['reporter_id'] ?? '?'),
                            sprintf('state=%s', $receipt['state'] ?? '?'),
                        ];
                        if (is_string($receipt['reason_code'] ?? null)) {
                            $parts[] = sprintf('reason=%s', $receipt['reason_code']);
                        }
                        if (is_string($receipt['error_code'] ?? null)) {
                            $parts[] = sprintf('error=%s', $receipt['error_code']);
                        }
                        if (is_string($receipt['delivery_id'] ?? null)) {
                            $parts[] = sprintf('delivery=%s', $receipt['delivery_id']);
                        }
                        $output->writeln(sprintf('      - %s', implode(', ', $parts)));
                    }
                }
                $output->writeln('    Receipts matrix:');
                $matrix = $report['reporting']['receipts_matrix'] ?? [];
                if (! is_array($matrix) || $matrix === []) {
                    $output->writeln('      (empty)');
                } else {
                    foreach ($matrix as $reporterId => $counts) {
                        $parts = [
                            sprintf('accepted=' . (int) ($counts['accepted'] ?? 0)),
                            sprintf('dropped=' . (int) ($counts['dropped'] ?? 0)),
                            sprintf('failed=' . (int) ($counts['failed'] ?? 0)),
                            sprintf('skipped=' . (int) ($counts['skipped'] ?? 0)),
                            sprintf('total=' . (int) ($counts['total'] ?? 0)),
                        ];
                        $output->writeln(sprintf('      - %s: %s', $reporterId, implode(' ', $parts)));
                    }
                }

                $stream = $report['reporting']['stream_confirmation'] ?? null;
                if (is_array($stream)) {
                    $output->writeln('  Stream:');
                    $output->writeln(sprintf(
                        '    Emitter: %s',
                        is_string($stream['emitter'] ?? null) && $stream['emitter'] !== '' ? $stream['emitter'] : '-',
                    ));
                    $output->writeln(sprintf(
                        '    Stream written: %s',
                        ($stream['stream_written'] ?? false) ? 'yes' : 'no',
                    ));
                    $output->writeln(sprintf(
                        '    Occurrence id: %s',
                        is_string($stream['occurrence_id'] ?? null) && $stream['occurrence_id'] !== '' ? $stream['occurrence_id'] : '-',
                    ));
                    if (is_array($stream['finalization'] ?? null) && $stream['finalization'] !== []) {
                        $output->writeln(sprintf(
                            '    Finalization: state=%s reporters=%d deliveries=%d',
                            is_string($stream['finalization']['state'] ?? null) ? $stream['finalization']['state'] : '-',
                            (int) ($stream['finalization']['reporter_count'] ?? 0),
                            (int) ($stream['finalization']['delivery_count'] ?? 0),
                        ));
                    }
                    $deliveries = is_array($stream['delivery_ids_by_reporter'] ?? null) ? $stream['delivery_ids_by_reporter'] : [];
                    if ($deliveries !== []) {
                        $output->writeln('    Deliveries by reporter:');
                        foreach ($deliveries as $reporterId => $deliveryIds) {
                            $rows = is_array($deliveryIds) ? $deliveryIds : [];
                            if ($rows === []) {
                                $output->writeln(sprintf('      - %s: (none)', $reporterId));
                                continue;
                            }
                            $preview = [];
                            foreach (array_slice($rows, 0, 4) as $deliveryId) {
                                if (is_string($deliveryId) && $deliveryId !== '') {
                                    $preview[] = substr($deliveryId, 0, 12);
                                }
                            }
                            $ellipsis = count($rows) > count($preview) ? ', …' : '';
                            $output->writeln(sprintf(
                                '      - %s (%d): %s%s',
                                $reporterId,
                                count($rows),
                                implode(', ', $preview),
                                $ellipsis,
                            ));
                        }
                    }
                    if (is_array($stream['deduplication'] ?? null) && ($stream['deduplication'] ?? []) !== []) {
                        $output->writeln(sprintf(
                            '    Deduplication: %s',
                            implode(', ', array_values(array_filter($stream['deduplication'], static fn (mixed $v): bool => is_string($v) && $v !== ''))),
                        ));
                    }
                }

                $output->writeln('  Recovery:');
                $recovery = $report['recovery']['decision'] ?? null;
                if (! is_array($recovery)) {
                    $output->writeln('    Decision: unavailable');
                } else {
                    $output->writeln(sprintf('    Action: %s', $recovery['action'] ?? 'none'));
                    $output->writeln(sprintf('    Reason code: %s', $recovery['reason_code'] ?? 'none'));
                    if (is_array($recovery['reasons'] ?? null) && $recovery['reasons'] !== []) {
                        $output->writeln(sprintf('    Reasons: %s', implode(', ', $recovery['reasons'])));
                    }
                    $output->writeln(sprintf(
                        '    Automatic replay enabled: %s',
                        $recovery['automatic_replay_enabled'] ? 'yes' : 'no',
                    ));
                    $output->writeln(sprintf(
                        '    Replay allowed for code: %s',
                        $recovery['replay_allowed_for_code'] ? 'yes' : 'no',
                    ));
                    $output->writeln(sprintf('    Fallback action: %s', $recovery['fallback_action'] ?? 'none'));
                }
            }

            if ($matrix && isset($report['bridge_matrix']) && is_array($report['bridge_matrix'])) {
                $output->writeln('  Bridge matrix:');
                foreach ($report['bridge_matrix'] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $output->writeln(sprintf(
                        '    - bridge=%s kind=%s target=%s status=%s exit=%s media_type=%s',
                        $row['bridge'] ?? '?',
                        $row['transport_kind'] ?? '?',
                        $row['target'] ?? '?',
                        $row['status'] ?? '-',
                        $row['exit_code'] ?? '-',
                        $row['media_type'] ?? '?',
                    ));
                    $reporting = is_array($row['reporting'] ?? null) ? $row['reporting'] : [];
                    $output->writeln(sprintf(
                        '      reporting: policy=%s ignored=%s reasons=%s',
                        ($reporting['should_report'] ?? false) ? 'allow' : 'skip',
                        ($reporting['ignored_by_policy'] ?? false) ? 'yes' : 'no',
                        is_array($reporting['reasons'] ?? null) && $reporting['reasons'] !== []
                            ? implode(',', $reporting['reasons'])
                            : 'none',
                    ));
                    $recovery = is_array($row['recovery'] ?? null) ? $row['recovery'] : [];
                    $output->writeln(sprintf(
                        '      recovery: action=%s fallback=%s replay=%s',
                        $recovery['action'] ?? 'none',
                        $recovery['fallback_action'] ?? 'none',
                        ($recovery['replay_allowed_for_code'] ?? false) ? 'yes' : 'no',
                    ));
                    if (is_array($row['runtime'] ?? null) && $row['runtime'] !== []) {
                        $output->writeln(sprintf(
                            '      runtime: scope=%s finalized=%s kind=%s',
                            $row['runtime']['scope_id'] ?? '?',
                            ($row['runtime']['finalized'] ?? false) ? 'yes' : 'no',
                            is_array($row['runtime']['finalization'] ?? null) ? ($row['runtime']['finalization']['kind'] ?? '-') : '-',
                        ));
                    }
                    if (is_array($row['receipts'] ?? null) && $row['receipts'] !== []) {
                        $output->writeln('      receipts:');
                        foreach ($row['receipts'] as $receipt) {
                            $parts = [
                                sprintf('reporter=%s', $receipt['reporter_id'] ?? '?'),
                                sprintf('state=%s', $receipt['state'] ?? '?'),
                            ];
                            if (is_string($receipt['reason_code'] ?? null)) {
                                $parts[] = sprintf('reason=%s', $receipt['reason_code']);
                            }
                            if (is_string($receipt['error_code'] ?? null)) {
                                $parts[] = sprintf('error=%s', $receipt['error_code']);
                            }
                            $output->writeln(sprintf('        - %s', implode(', ', $parts)));
                        }
                    }
                }
            }

            if ($effects) {
                if (is_array($report['effect_matrix'] ?? null) && $report['effect_matrix'] !== []) {
                    $output->writeln('  Effects summary:');
                    foreach ($report['effect_matrix'] as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $output->writeln(sprintf(
                            '    - effect=%s rows=%s policy_allow=%s',
                            $row['effect'] ?? '?',
                            $row['rows'] ?? 0,
                            $row['policy_allow_rows'] ?? 0,
                        ));
                        if (is_array($row['bridges'] ?? null) && $row['bridges'] !== []) {
                            $output->writeln(sprintf('      bridges: %s', implode(',', $row['bridges'])));
                        }
                    }
                }
                if (is_array($report['runtime'] ?? null) && $report['runtime'] !== []) {
                    $output->writeln('  Runtime:');
                    $output->writeln(sprintf('    scopes_total: %d', is_countable($report['runtime']['scopes'] ?? null) ? count($report['runtime']['scopes']) : 0));
                    if (is_array($report['runtime']['scopes'] ?? null)) {
                        foreach ($report['runtime']['scopes'] as $scopeRow) {
                            $finalKind = is_array($scopeRow['finalization'] ?? null)
                                ? ($scopeRow['finalization']['kind'] ?? '-')
                                : '-';
                            $output->writeln(sprintf(
                                '    - transport=%s operation_id=%s scope=%s finalization=%s finalized=%s',
                                $scopeRow['transport'] ?? '?',
                                $scopeRow['runtime_operation_id'] ?? '-',
                                $scopeRow['scope_id'] ?? '?',
                                $finalKind,
                                ($scopeRow['finalized'] ?? false) ? 'yes' : 'no',
                            ));
                        }
                    }
                    if (is_array($report['runtime']['reset'] ?? null) && $report['runtime']['reset'] !== []) {
                        $output->writeln(sprintf(
                            '    reset report: scopes_closed=%s leak=%s',
                            $report['runtime']['reset']['scopes_closed'] ?? '?',
                            ($report['runtime']['reset']['leak_detected'] ?? false) ? 'yes' : 'no',
                        ));
                    }
                }
                if (is_array($report['receipts_matrix_by_scope'] ?? null) && $report['receipts_matrix_by_scope'] !== []) {
                    $output->writeln('  Receipts by scope:');
                    foreach ($report['receipts_matrix_by_scope'] as $scopeId => $scopeReceipts) {
                        $output->writeln(sprintf('    scope=%s', $scopeId));
                        if (! is_array($scopeReceipts)) {
                            continue;
                        }
                        foreach ($scopeReceipts as $receipt) {
                            $parts = [
                                sprintf('reporter=%s', $receipt['reporter_id'] ?? '?'),
                                sprintf('state=%s', $receipt['state'] ?? '?'),
                            ];
                            if (is_string($receipt['reason_code'] ?? null)) {
                                $parts[] = sprintf('reason=%s', $receipt['reason_code']);
                            }
                            if (is_string($receipt['error_code'] ?? null)) {
                                $parts[] = sprintf('error=%s', $receipt['error_code']);
                            }
                            $output->writeln(sprintf('      - %s', implode(', ', $parts)));
                        }
                    }
                }
            }

            return $exitCode;
        });
    }

    private function stringOption(Input $input, string $name): ?string
    {
        $value = $input->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *   throwable: Throwable,
     *   description: string,
     *   context: ExceptionContext,
     *   transport_context: TransportContext
     * }
     */
    private function fixture(string $type, string $transport, ExceptionCompilationPlan $plan): array
    {
        $type = strtolower(trim($type));
        $transport = strtolower(trim($transport));
        $transportContext = $this->transportContext($transport, $plan);
        $baseAttributes = [
            'surface' => $transportContext->kind,
            'transport_kind' => $transportContext->kind,
            'transport_route_profile' => $transportContext->routeProfile,
            'request_id' => 'req-explain-001',
            'operation_id' => 'op-explain-001',
            'navigation_id' => 'nav-explain-001',
            'idempotency_verified' => true,
        ];

        if ($transport === 'spa') {
            $baseAttributes['spa_target_scope'] = 'component';
            $baseAttributes['spa_target_id'] = 'user-profile-card';
            $baseAttributes['spa_target_revision'] = 7;
            $baseAttributes['spa_reconcile_operation_ref'] = 'reconcile-42';
            $baseAttributes['spa_reconcile_route_key'] = 'profile.show';
        }

        [$throwable, $description, $origin] = match ($type) {
            'validation' => [
                new ValidationException(
                    errors: [
                        'email' => ['The email field is required.'],
                        'password' => ['The password must be at least 12 characters.'],
                    ],
                    message: 'Synthetic validation fixture.',
                ),
                'Validation failure with redacted field projection.',
                'validation',
            ],
            'auth_required' => [
                new AuthenticationRequiredException('Synthetic authentication required.'),
                'Authentication required fixture.',
                'authentication',
            ],
            'auth_failed' => [
                new AuthenticationException('Synthetic authentication failed.', 'auth.invalid_credentials'),
                'Authentication failure fixture.',
                'authentication',
            ],
            'throttled' => [
                new ThrottleDeniedException(retryAfterSeconds: 30, identifier: 'demo-user'),
                'Authentication throttling fixture with retry-after metadata.',
                'authentication',
            ],
            'not_found' => [
                new ViewNotFoundException('Synthetic view not found.'),
                'Not found fixture mapped through the view bridge.',
                'view',
            ],
            'db_retryable' => [
                new ExecutionException(new ExecutionFailure(
                    phase: 'query',
                    category: 'statement_execution',
                    message: 'Synthetic retryable database execution failure.',
                    retryable: true,
                )),
                'Retryable database execution fixture.',
                'database',
            ],
            'db_default' => [
                new ExecutionException(new ExecutionFailure(
                    phase: 'commit',
                    category: 'statement_execution',
                    message: 'Synthetic non-retryable database execution failure.',
                    retryable: false,
                )),
                'Default database execution fixture.',
                'database',
            ],
            'config_invalid' => [
                new \InvalidArgumentException('Synthetic configuration failure.'),
                'Configuration fixture routed to configuration.invalid.',
                'configuration',
            ],
            'internal' => [
                new ViewRenderException('resources/views/dashboard/index.volt.php'),
                'Generic internal error fixture.',
                'view',
            ],
            'cancelled' => [
                new \RuntimeException('Synthetic operation was cancelled.'),
                'Cancelled operation fixture routed through recovery abort.',
                'cancelled',
            ],
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown explain fixture [%s]. Supported fixtures: validation, auth_required, auth_failed, throttled, not_found, db_retryable, db_default, config_invalid, internal, cancelled.',
                $type,
            )),
        };

        if ($type === 'cancelled') {
            $baseAttributes['cancelled'] = true;
        }

        return [
            'throwable' => $throwable,
            'description' => $description,
            'context' => new ExceptionContext(
                scopeId: 'explain-scope',
                occurrenceId: null,
                correlationId: 'corr-explain-001',
                locale: (string) ($plan->config()['default_locale'] ?? 'en'),
                debug: $plan->debug(),
                attributes: $baseAttributes + ['origin' => $origin],
            ),
            'transport_context' => $transportContext,
        ];
    }

    private function transportContext(string $transport, ExceptionCompilationPlan $plan): TransportContext
    {
        return match ($transport) {
            'json', 'problem_json' => new TransportContext(
                kind: 'http',
                routeProfile: 'api',
                accept: ['application/problem+json'],
                locale: (string) ($plan->config()['default_locale'] ?? 'en'),
            ),
            'html' => new TransportContext(
                kind: 'http',
                routeProfile: 'browser',
                accept: ['text/html'],
                locale: (string) ($plan->config()['default_locale'] ?? 'en'),
            ),
            'spa' => new TransportContext(
                kind: 'http',
                routeProfile: 'spa',
                accept: ['application/vnd.voltstack.spa-error+json'],
                locale: (string) ($plan->config()['default_locale'] ?? 'en'),
                spaVersion: $plan->spaVersions()[0] ?? 1,
            ),
            'cli' => new TransportContext(
                kind: 'cli',
                routeProfile: 'text',
                accept: ['text/plain'],
            ),
            'cli-json' => new TransportContext(
                kind: 'cli',
                routeProfile: 'structured',
                accept: ['application/json'],
            ),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown explain transport [%s]. Supported transports: json, html, spa, cli, cli-json.',
                $transport,
            )),
        };
    }

    /**
     * @param SemanticExceptionMapperInterface $mapper
     * @return array{semantic: \Quantum\Exceptions\Model\SemanticError, matched_rule: array<string, mixed>|null, used_fallback: bool}
     */
    private function inspectMapping(
        SemanticExceptionMapperInterface $mapper,
        \Quantum\Exceptions\Model\FailureSnapshot $failure,
        ExceptionContext $context,
    ): array {
        if ($mapper instanceof DeterministicSemanticExceptionMapper || method_exists($mapper, 'inspect')) {
            /** @var array{semantic: \Quantum\Exceptions\Model\SemanticError, matched_rule: array<string, mixed>|null, used_fallback: bool} $inspection */
            $inspection = $mapper->inspect($failure, $context);

            return $inspection;
        }

        $semantic = $mapper->map($failure, $context);

        if ($semantic === null) {
            throw new \InvalidArgumentException('The semantic mapper did not produce a semantic error for the synthetic fixture.');
        }

        return [
            'semantic' => $semantic,
            'matched_rule' => null,
            'used_fallback' => false,
        ];
    }

    private function occurrenceId(string $type, string $transport, ExceptionCompilationPlan $plan): string
    {
        return 'explain-' . substr(sha1($type . '|' . $transport . '|' . $plan->fingerprint()), 0, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateReportingDecision(Application $app, \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor): array
    {
        $policy = $app->make(ExceptionReportingPolicy::class);
        $record = new ReportRecord(
            occurrenceId: $descriptor->occurrenceId,
            parentOccurrenceId: null,
            fingerprint: $this->reportFingerprint($descriptor),
            semantic: $descriptor->semantic,
            diagnostic: [],
            correlation: [
                'transport' => is_string($descriptor->contextSummary['transport_kind'] ?? null)
                    ? $descriptor->contextSummary['transport_kind']
                    : null,
            ],
        );
        $decision = $policy->decide(
            record: $record,
            reporterId: 'synthetic.explain',
            budget: new ReportBudget(),
            attemptNumber: 1,
            budgetExpired: false,
        );
        $reasons = [];
        if (! $decision->shouldReport && is_string($decision->reasonCode) && $decision->reasonCode !== '') {
            $reasons[] = $decision->reasonCode;
        }
        if ($decision->shouldReport) {
            $reasons[] = 'policy_allowed';
        }

        return [
            'should_report' => $decision->shouldReport,
            'ignored_by_policy' => ! $decision->shouldReport,
            'reasons' => $reasons,
        ];
    }

    private function reportFingerprint(\Quantum\Exceptions\Model\ExceptionDescriptor $descriptor): string
    {
        return substr(sha1(
            $descriptor->policyRevision . '|' . $descriptor->semantic->code . '|' . $descriptor->occurrenceId,
        ), 0, 40);
    }

    private function runReportingPipeline(
        Application $app,
        \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor,
        string $type,
        string $transport,
        ExceptionCompilationPlan $plan,
    ): ReportReceipt {
        try {
            $pipeline = $app->make(ExceptionReporterPipeline::class);
        } catch (\Throwable) {
            return new ReportReceipt(
                occurrenceId: $descriptor->occurrenceId,
                receipts: [],
                deduplicated: false,
                suppressionReasons: ['reporter_pipeline_unavailable'],
            );
        }

        $record = new ReportRecord(
            occurrenceId: $descriptor->occurrenceId,
            parentOccurrenceId: null,
            fingerprint: $this->reportFingerprint($descriptor),
            semantic: $descriptor->semantic,
            diagnostic: [
                'synthetic' => true,
                'fixture' => $type,
                'fixture_transport' => $transport,
                'policy_revision' => $descriptor->policyRevision,
            ],
            correlation: [
                'transport' => is_string($descriptor->contextSummary['transport_kind'] ?? null)
                    ? $descriptor->contextSummary['transport_kind']
                    : null,
                'correlation_id' => is_string($descriptor->contextSummary['correlation_id'] ?? null)
                    ? $descriptor->contextSummary['correlation_id']
                    : null,
                'request_id' => is_string($descriptor->contextSummary['request_id'] ?? null)
                    ? $descriptor->contextSummary['request_id']
                    : null,
            ],
        );

        try {
            return $pipeline->report($record, new ReportBudget());
        } catch (\Throwable $exception) {
            $receipt = new ReporterReceipt(
                reporterId: 'synthetic.explain.pipeline',
                state: ReporterReceiptState::Failed,
                durationMs: 0,
                errorCode: $exception::class !== '' ? $exception::class : 'runtime_exception',
                reasonCode: 'reporter_pipeline_failed',
            );

            return new ReportReceipt(
                occurrenceId: $descriptor->occurrenceId,
                receipts: [$receipt],
                deduplicated: false,
                suppressionReasons: array_values(array_filter([
                    is_string($exception->getMessage()) && $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : null,
                ])),
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeReceipts(ReportReceipt $receipt, bool $include): array
    {
        if (! $include) {
            return [];
        }

        $aliasMap = [
            StructuredErrorLogReporter::class => 'exceptions.log',
            EventDispatcherExceptionReporter::class => 'exceptions.events',
            TelemetryExceptionReporter::class => 'exceptions.telemetry',
        ];

        $serialized = [];
        foreach ($receipt->receipts as $item) {
            $row = [
                'reporter_id' => $aliasMap[$item->reporterId] ?? $item->reporterId,
                'state' => $item->state->value,
                'duration_ms' => $item->durationMs,
            ];
            if ($item->errorCode !== null) {
                $row['error_code'] = $item->errorCode;
            }
            if ($item->reasonCode !== null) {
                $row['reason_code'] = $item->reasonCode;
            }
            if ($item->deliveryId !== null) {
                $row['delivery_id'] = $item->deliveryId;
            }
            $serialized[] = $row;
        }

        return $serialized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluateRecoveryDecision(
        Application $app,
        \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor,
        TransportContext $transportContext,
    ): ?array {
        try {
            $recoveryPolicy = $app->make(RecoveryPolicyInterface::class);
        } catch (\Throwable) {
            return [
                'simulated' => false,
                'reason' => 'recovery_policy_unavailable',
            ];
        }

        $config = $app->make(ExceptionCompilationPlan::class)->config();
        $recoveryConfig = is_array($config['recovery'] ?? null) ? $config['recovery'] : [];
        $automaticReplayEnabled = (bool) ($recoveryConfig['automatic_replay'] ?? false);

        $retryAdviceAllowsReplay = in_array(
            $descriptor->semantic->retryAdvice->value,
            ['retry_allowed', 'conditional'],
            true,
        );
        $replayAllowedForCode = $automaticReplayEnabled && $retryAdviceAllowsReplay;

        $effect = $descriptor->contextSummary['effect'] ?? null;
        if ($effect instanceof Effect) {
            $resolvedEffect = $effect;
        } else {
            $resolvedEffect = Effect::Unknown;
        }

        $recoveryContext = new RecoveryContext(
            owner: 'synthetic.explain',
            effect: $resolvedEffect,
            idempotencyVerified: (bool) ($descriptor->contextSummary['idempotency_verified'] ?? false),
            attempt: 0,
            deadlineMs: null,
            cancelled: (bool) ($descriptor->contextSummary['cancelled'] ?? false),
            capabilities: [
                'transport_kind' => $transportContext->kind,
                'route_profile' => $transportContext->routeProfile,
            ],
        );

        try {
            $decision = $recoveryPolicy->decide($descriptor, $recoveryContext);
            $action = $decision->action->value;
            $reasonCode = $decision->reasonCode;
        } catch (\Throwable) {
            $action = 'none';
            $reasonCode = 'recovery_policy_failed';
        }

        $fallbackAction = match (true) {
            $action === 'abort' => 'reject_surface',
            $action === 'reconcile' => 'invoke_reconcile_hook',
            $action === 'retry_advice' => $replayAllowedForCode ? 'schedule_replay' : 'surface_error',
            $action === 'fallback_advice' => 'fallback_response',
            default => 'surface_error',
        };

        $reasons = [];
        if (! $automaticReplayEnabled) {
            $reasons[] = 'automatic_replay_disabled';
        }
        if (! $retryAdviceAllowsReplay) {
            $reasons[] = 'retry_advice_blocks_replay';
        }
        $reasons[] = sprintf('policy_action=%s', $action);

        return [
            'simulated' => true,
            'action' => $action,
            'reason_code' => $reasonCode,
            'automatic_replay_enabled' => $automaticReplayEnabled,
            'replay_allowed_for_code' => $replayAllowedForCode,
            'fallback_action' => $fallbackAction,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildBridgeMatrix(Application $app, string $type, ExceptionCompilationPlan $plan, bool $includeReceipts, bool $includeRuntime): array
    {
        $bridges = ['json', 'html', 'spa', 'cli', 'cli-json'];
        $rows = [];

        foreach ($bridges as $bridge) {
            $rows[] = $this->bridgeMatrixRow($app, $type, $bridge, $plan, $includeReceipts, $includeRuntime);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function bridgeMatrixRow(Application $app, string $type, string $bridge, ExceptionCompilationPlan $plan, bool $includeReceipts, bool $includeRuntime): array
    {
        try {
            $fixture = $this->fixture($type, $bridge, $plan);
            $context = $fixture['context'];
            $failure = $app->make(ExceptionNormalizerInterface::class)->normalize($fixture['throwable'], $context);
            $mapper = $app->make(SemanticExceptionMapperInterface::class);
            $inspection = $this->inspectMapping($mapper, $failure, $context);
            $descriptor = (new ExceptionDescriptorFactory($plan->policyRevision()))->create(
                occurrenceId: $this->occurrenceId($type, $bridge, $plan),
                failure: $failure,
                semantic: $inspection['semantic'],
                context: $context,
            );
            $publicError = (new PublicErrorProjector())->project($descriptor);
            $bridgeContext = $fixture['transport_context'];
            $transportPlan = $app->make(TransportMapperInterface::class)->map($descriptor, $bridgeContext);
            $rendered = $app->make(ExceptionRendererInterface::class)->render($publicError, $transportPlan);
            $reporting = $this->evaluateReportingDecision($app, $descriptor);
            $recovery = $this->evaluateRecoveryDecision($app, $descriptor, $bridgeContext);

            $row = [
                'bridge' => $bridge,
                'transport_kind' => $bridgeContext->kind,
                'route_profile' => $bridgeContext->routeProfile,
                'target' => $transportPlan->target,
                'status' => $transportPlan->status,
                'exit_code' => $transportPlan->exitCode,
                'media_type' => $rendered->mediaType,
                'mapping' => [
                    'code' => $inspection['semantic']->code,
                    'matched_rule_id' => is_array($inspection['matched_rule'] ?? null)
                        ? ($inspection['matched_rule']['id'] ?? null)
                        : null,
                    'used_fallback' => $inspection['used_fallback'],
                ],
                'reporting' => [
                    'should_report' => (bool) ($reporting['should_report'] ?? false),
                    'ignored_by_policy' => (bool) ($reporting['ignored_by_policy'] ?? false),
                    'reasons' => is_array($reporting['reasons'] ?? null) ? array_values($reporting['reasons']) : [],
                ],
                'recovery' => [
                    'action' => is_array($recovery) ? ($recovery['action'] ?? 'none') : 'none',
                    'reason_code' => is_array($recovery) ? ($recovery['reason_code'] ?? null) : null,
                    'fallback_action' => is_array($recovery) ? ($recovery['fallback_action'] ?? 'none') : 'none',
                    'automatic_replay_enabled' => is_array($recovery) ? (bool) ($recovery['automatic_replay_enabled'] ?? false) : false,
                    'replay_allowed_for_code' => is_array($recovery) ? (bool) ($recovery['replay_allowed_for_code'] ?? false) : false,
                ],
            ];

            if ($includeReceipts) {
                $receipt = $this->runReportingPipeline($app, $descriptor, $type, $bridge, $plan);
                $row['receipts'] = $this->serializeReceipts($receipt, true);
            }

            if ($includeRuntime) {
                $row['runtime'] = $this->runtimeRowForBridge($app, $type, $bridge, $plan);
            }

            return $row;
        } catch (\Throwable $exception) {
            return [
                'bridge' => $bridge,
                'transport_kind' => null,
                'route_profile' => null,
                'target' => 'bridge_error',
                'status' => null,
                'exit_code' => null,
                'media_type' => 'application/vnd.voltstack.bridge-error+json',
                'mapping' => [
                    'code' => null,
                    'matched_rule_id' => null,
                    'used_fallback' => false,
                ],
                'reporting' => [
                    'should_report' => false,
                    'ignored_by_policy' => true,
                    'reasons' => ['bridge_row_failed'],
                ],
                'recovery' => [
                    'action' => 'none',
                    'reason_code' => 'bridge_row_failed',
                    'fallback_action' => 'surface_error',
                    'automatic_replay_enabled' => false,
                    'replay_allowed_for_code' => false,
                ],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeRowForBridge(Application $app, string $type, string $bridge, ExceptionCompilationPlan $plan): array
    {
        return $this->runRuntimeLifecycle(
            app: $app,
            type: $type,
            bridge: $bridge,
            transport: $this->runtimeTransportForBridge($bridge),
            plan: $plan,
            finalizeWithFailure: in_array($type, ['cancelled', 'internal', 'db_default', 'db_retryable'], true),
        );
    }

    private function runtimeTransportForBridge(string $bridge): string
    {
        return match ($bridge) {
            'cli', 'cli-json' => 'cli',
            default => 'http',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runRuntimeLifecycle(
        Application $app,
        string $type,
        string $bridge,
        string $transport,
        ExceptionCompilationPlan $plan,
        bool $finalizeWithFailure,
    ): array {
        try {
            $runtimeBridge = $app->make(ExceptionRuntimeBridge::class);
            $operationId = substr(sha1('runtime|' . $type . '|' . $bridge . '|' . $plan->fingerprint()), 0, 24);
            $deadline = microtime(true) + 5.0;
            $operation = new RuntimeOperation(
                operationId: $operationId,
                transport: $transport,
                deadlineAt: $deadline,
                metadata: [
                    'synthetic' => true,
                    'bridge' => $bridge,
                    'fixture' => $type,
                ],
            );
            $scope = $runtimeBridge->begin($operation);
            $outcome = $finalizeWithFailure
                ? FinalizationOutcome::failed([
                    'fixture' => $type,
                    'bridge' => $bridge,
                ])
                : FinalizationOutcome::completed([
                    'fixture' => $type,
                    'bridge' => $bridge,
                ]);

            $runtimeBridge->finalize($scope, $outcome);

            return [
                'scope_id' => $scope->id(),
                'runtime_operation_id' => $operationId,
                'transport' => $transport,
                'deadline_at' => $deadline,
                'finalized' => $scope->isFinalized(),
                'finalized_at' => $scope->finalizedAt(),
                'finalization' => [
                    'kind' => $outcome->kind,
                    'metadata' => $outcome->metadata,
                ],
                'metadata' => [
                    'synthetic' => true,
                    'fixture' => $type,
                    'bridge' => $bridge,
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'scope_id' => null,
                'runtime_operation_id' => null,
                'transport' => $transport,
                'finalized' => false,
                'finalization' => [
                    'kind' => 'bridge_unavailable',
                    'metadata' => [
                        'error' => $exception->getMessage(),
                    ],
                ],
                'metadata' => [
                    'synthetic' => true,
                    'fixture' => $type,
                    'bridge' => $bridge,
                ],
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $bridgeMatrix
     * @param array<string, mixed>|null $runtimeDiagnostic
     * @return list<array<string, mixed>>
     */
    private function buildEffectMatrix(string $semanticCode, array $bridgeMatrix, ?array $runtimeDiagnostic): array
    {
        $effects = [
            'replay' => [
                'effect' => 'replay',
                'rows' => 0,
                'policy_allow_rows' => 0,
                'bridges' => [],
                'semantic_codes' => [],
                'scope_ids' => [],
            ],
            'reconcile' => [
                'effect' => 'reconcile',
                'rows' => 0,
                'policy_allow_rows' => 0,
                'bridges' => [],
                'semantic_codes' => [],
                'scope_ids' => [],
            ],
            'abort' => [
                'effect' => 'abort',
                'rows' => 0,
                'policy_allow_rows' => 0,
                'bridges' => [],
                'semantic_codes' => [],
                'scope_ids' => [],
            ],
            'none' => [
                'effect' => 'none',
                'rows' => 0,
                'policy_allow_rows' => 0,
                'bridges' => [],
                'semantic_codes' => [],
                'scope_ids' => [],
            ],
        ];

        $rows = $bridgeMatrix;
        if (is_array($runtimeDiagnostic['scopes'] ?? null)) {
            foreach ($runtimeDiagnostic['scopes'] as $scope) {
                if (! is_array($scope) || ! isset($scope['bridge']) || ! is_string($scope['bridge'])) {
                    continue;
                }
                $rows[] = [
                    'bridge' => $scope['bridge'],
                    'recovery' => [
                        'action' => $scope['recovery_action'] ?? 'none',
                    ],
                    'reporting' => [
                        'should_report' => (bool) ($scope['policy_should_report'] ?? false),
                    ],
                    'mapping' => [
                        'code' => $semanticCode,
                    ],
                    'runtime' => [
                        'scope_id' => is_string($scope['scope_id'] ?? null) ? $scope['scope_id'] : null,
                    ],
                ];
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bridgeName = is_string($row['bridge'] ?? null) ? $row['bridge'] : '';
            if ($bridgeName === '') {
                continue;
            }
            $action = is_array($row['recovery'] ?? null) && is_string($row['recovery']['action'] ?? null)
                ? $row['recovery']['action']
                : 'none';
            $effectKey = match (true) {
                $action === 'retry_advice' => 'replay',
                $action === 'reconcile' => 'reconcile',
                $action === 'abort' => 'abort',
                default => 'none',
            };
            $effects[$effectKey]['rows']++;
            if ((bool) ($row['reporting']['should_report'] ?? false)) {
                $effects[$effectKey]['policy_allow_rows']++;
            }
            if (! in_array($bridgeName, $effects[$effectKey]['bridges'], true)) {
                $effects[$effectKey]['bridges'][] = $bridgeName;
            }
            $rowCode = is_array($row['mapping'] ?? null) && is_string($row['mapping']['code'] ?? null) ? $row['mapping']['code'] : $semanticCode;
            if (! in_array($rowCode, $effects[$effectKey]['semantic_codes'], true)) {
                $effects[$effectKey]['semantic_codes'][] = $rowCode;
            }
            $scopeId = is_array($row['runtime'] ?? null) && is_string($row['runtime']['scope_id'] ?? null) ? $row['runtime']['scope_id'] : null;
            if (is_string($scopeId) && ! in_array($scopeId, $effects[$effectKey]['scope_ids'], true)) {
                $effects[$effectKey]['scope_ids'][] = $scopeId;
            }
        }

        return array_values(array_filter(
            $effects,
            /** @param array<string, mixed> $row */
            static fn (array $row): bool => (int) ($row['rows'] ?? 0) > 0,
        ));
    }

    /**
     * @param list<array<string, mixed>> $serializedReceipts
     * @param list<array<string, mixed>> $bridgeMatrix
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildReceiptsMatrixByScope(array $serializedReceipts, string $occurrenceId, array $bridgeMatrix): array
    {
        $scopeReceipts = [];
        $fixtureScopeId = 'scope.main.' . $occurrenceId;
        if ($serializedReceipts !== []) {
            $scopeReceipts[$fixtureScopeId] = $serializedReceipts;
        }

        foreach ($bridgeMatrix as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowReceipts = is_array($row['receipts'] ?? null) ? $row['receipts'] : [];
            $rowScopeId = is_array($row['runtime'] ?? null) && is_string($row['runtime']['scope_id'] ?? null)
                ? $row['runtime']['scope_id']
                : (is_string($row['bridge'] ?? null) ? 'scope.bridge.' . $row['bridge'] : null);
            if (! is_string($rowScopeId) || $rowReceipts === []) {
                continue;
            }
            $scopeReceipts[$rowScopeId] = $rowReceipts;
        }

        return $scopeReceipts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRuntimeDiagnostic(Application $app, string $type, ExceptionCompilationPlan $plan, bool $includeReceipts): ?array
    {
        $scopes = [];
        $resetScopesClosed = 0;
        $resetLeaks = 0;

        foreach (['runtime_http' => 'http', 'runtime_job' => 'cli'] as $bridgeName => $transport) {
            $row = $this->runRuntimeLifecycle(
                app: $app,
                type: $type,
                bridge: $bridgeName,
                transport: $transport,
                plan: $plan,
                finalizeWithFailure: in_array($type, ['cancelled', 'internal', 'db_default', 'db_retryable'], true),
            );
            $fixture = $this->fixture($type, $transport === 'http' ? 'json' : 'cli', $plan);
            $context = $fixture['context'];
            try {
                $failure = $app->make(ExceptionNormalizerInterface::class)->normalize($fixture['throwable'], $context);
                $mapper = $app->make(SemanticExceptionMapperInterface::class);
                $inspection = $this->inspectMapping($mapper, $failure, $context);
                $descriptor = (new ExceptionDescriptorFactory($plan->policyRevision()))->create(
                    occurrenceId: $this->occurrenceId($type, $bridgeName, $plan),
                    failure: $failure,
                    semantic: $inspection['semantic'],
                    context: $context,
                );
                $bridgeContext = $fixture['transport_context'];
                $reporting = $this->evaluateReportingDecision($app, $descriptor);
                $recovery = $this->evaluateRecoveryDecision($app, $descriptor, $bridgeContext);

                $row['bridge'] = $bridgeName;
                $row['recovery_action'] = is_array($recovery) && is_string($recovery['action'] ?? null) ? $recovery['action'] : 'none';
                $row['policy_should_report'] = (bool) ($reporting['should_report'] ?? false);
                $row['semantic_code'] = $inspection['semantic']->code;

                if ($includeReceipts) {
                    $receipt = $this->runReportingPipeline($app, $descriptor, $type, $bridgeName, $plan);
                    $row['receipts'] = $this->serializeReceipts($receipt, true);
                }
            } catch (\Throwable $exception) {
                $row['bridge'] = $bridgeName;
                $row['error'] = $exception->getMessage();
                $row['recovery_action'] = 'none';
                $row['policy_should_report'] = false;
                $row['semantic_code'] = null;
            }
            $scopes[] = $row;
            $resetScopesClosed++;
            if (($row['finalized'] ?? false) !== true) {
                $resetLeaks++;
            }
        }

        return [
            'scopes' => $scopes,
            'reset' => [
                'scopes_closed' => $resetScopesClosed,
                'leak_detected' => $resetLeaks > 0,
                'leaks' => $resetLeaks,
                'mode' => 'simulated',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $serializedReceipts
     * @return array<string, array{accepted:int,dropped:int,failed:int,skipped:int,total:int}>
     */
    private function buildReceiptsMatrix(array $serializedReceipts): array
    {
        $matrix = [];

        foreach ($serializedReceipts as $receipt) {
            $reporterId = is_string($receipt['reporter_id'] ?? null) && $receipt['reporter_id'] !== ''
                ? $receipt['reporter_id']
                : 'unknown';
            $state = is_string($receipt['state'] ?? null) && $receipt['state'] !== ''
                ? $receipt['state']
                : 'unknown';

            if (! isset($matrix[$reporterId])) {
                $matrix[$reporterId] = [
                    'accepted' => 0,
                    'dropped' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'total' => 0,
                ];
            }

            $counterKey = match ($state) {
                'accepted' => 'accepted',
                'dropped' => 'dropped',
                'failed' => 'failed',
                'skipped' => 'skipped',
                default => null,
            };

            if ($counterKey !== null) {
                $matrix[$reporterId][$counterKey]++;
            }

            $matrix[$reporterId]['total']++;
        }

        return $matrix;
    }

    /**
     * @return array{
     *   bridge: string,
     *   kind: string|null,
     *   route_profile: string|null,
     *   target: string|null,
     *   http?: array<string, mixed>,
     *   spa?: array<string, mixed>,
     *   cli?: array<string, mixed>
     * }
     */
    private function buildTransportDiagnostic(
        Application $app,
        \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor,
        TransportContext $transportContext,
    ): array {
        try {
            $mapper = $app->make(TransportMapperInterface::class);
        } catch (\Throwable) {
            return [
                'bridge' => 'unavailable',
                'kind' => $transportContext->kind !== '' ? $transportContext->kind : null,
                'route_profile' => $transportContext->routeProfile !== '' ? $transportContext->routeProfile : null,
                'target' => null,
            ];
        }

        $bridgeClass = $mapper::class;
        $bridge = match (true) {
            $mapper instanceof CompositeTransportMapper => 'composite',
            $mapper instanceof HttpTransportMapper => 'http',
            $mapper instanceof CliTransportMapper => 'cli',
            default => $bridgeClass !== '' ? $bridgeClass : 'custom',
        };

        $diagnostic = [
            'bridge' => $bridge,
            'mapper_class' => $bridgeClass,
            'kind' => $transportContext->kind !== '' ? $transportContext->kind : null,
            'route_profile' => $transportContext->routeProfile !== '' ? $transportContext->routeProfile : null,
            'target' => null,
        ];

        try {
            $plan = $mapper->map($descriptor, $transportContext);
            $diagnostic['target'] = $plan->target;

            if ($transportContext->kind === 'http') {
                $diagnostic['http'] = [
                    'target' => $plan->target,
                    'status' => $plan->status,
                    'retry_after_seconds' => $plan->retryAfterSeconds,
                    'has_problem_extensions' => is_array($plan->metadata['problem_extensions'] ?? null),
                ];
                if (is_array($plan->metadata['spa_version'] ?? null) || is_int($plan->metadata['spa_version'] ?? null) || str_contains((string) ($plan->target ?? ''), 'spa')) {
                    $diagnostic['spa'] = [
                        'version' => $plan->metadata['spa_version'] ?? null,
                        'target_scope' => $plan->metadata['target_scope'] ?? null,
                        'action' => $plan->spaAction ?? null,
                        'effect' => $plan->metadata['effect'] ?? null,
                    ];
                }
            } elseif ($transportContext->kind === 'cli') {
                $diagnostic['cli'] = [
                    'target' => $plan->target,
                    'exit_code' => $plan->exitCode,
                ];
            }
        } catch (\Throwable) {
            // Dejar target=null ya que el mapper no pudo resolver; no propagar.
        }

        return $diagnostic;
    }

    /**
     * @return array{
     *   emitter: string,
     *   stream_written: bool,
     *   occurrence_id: string,
     *   delivery_ids_by_reporter: array<string, list<string>>,
     *   finalization: array{state:string,reporter_count:int,delivery_count:int,suppressed:bool,suppression_reasons:list<string>},
     *   deduplication: list<string>
     * }|null
     */
    private function buildStreamConfirmation(
        Application $app,
        \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor,
        string $type,
        string $transport,
        ExceptionCompilationPlan $plan,
        ReportReceipt $firstReceipt,
        bool $include,
    ): ?array {
        if (! $include) {
            return null;
        }

        $emittedDeliveries = [];
        $aliasMap = [
            StructuredErrorLogReporter::class => 'exceptions.log',
            EventDispatcherExceptionReporter::class => 'exceptions.events',
            TelemetryExceptionReporter::class => 'exceptions.telemetry',
        ];

        $deduplication = [];
        $deliveryCount = 0;
        $suppressed = false;
        $suppressionReasons = [];

        foreach ($firstReceipt->receipts as $receipt) {
            $reporterAlias = $aliasMap[$receipt->reporterId] ?? $receipt->reporterId;
            if (! isset($emittedDeliveries[$reporterAlias])) {
                $emittedDeliveries[$reporterAlias] = [];
            }
            if (is_string($receipt->deliveryId) && $receipt->deliveryId !== '') {
                $emittedDeliveries[$reporterAlias][] = $receipt->deliveryId;
                $deliveryCount++;
            }
        }

        // Confirmación real de stream: re-disparar el pipeline sobre el mismo occurrence id.
        // ExceptionReporterPipeline + OccurrenceRegistry (cuando existe) aplican deduplicación,
        // por lo que esta segunda invocación es segura y permite validar que no hay duplicados.
        try {
            $secondReceipt = $this->runReportingPipeline($app, $descriptor, $type, $transport, $plan);
            foreach ($secondReceipt->receipts as $receipt) {
                $reporterAlias = $aliasMap[$receipt->reporterId] ?? $receipt->reporterId;
                if ($receipt->state === ReporterReceiptState::Skipped) {
                    if (! in_array('skipped', $deduplication, true)) {
                        $deduplication[] = 'skipped';
                    }
                }
                if (is_string($receipt->reasonCode) && $receipt->reasonCode !== '' && ! in_array($receipt->reasonCode, $deduplication, true)) {
                    $deduplication[] = $receipt->reasonCode;
                }
                if (is_string($receipt->deliveryId) && $receipt->deliveryId !== '') {
                    if (! isset($emittedDeliveries[$reporterAlias])) {
                        $emittedDeliveries[$reporterAlias] = [];
                    }
                    if (! in_array($receipt->deliveryId, $emittedDeliveries[$reporterAlias], true)) {
                        $emittedDeliveries[$reporterAlias][] = $receipt->deliveryId;
                        $deliveryCount++;
                    }
                }
            }
            if ($secondReceipt->deduplicated) {
                if (! in_array('second_pass_deduplicated', $deduplication, true)) {
                    $deduplication[] = 'second_pass_deduplicated';
                }
            }
            $suppressed = $secondReceipt->suppressionReasons !== [];
            $suppressionReasons = $secondReceipt->suppressionReasons;
        } catch (\Throwable $e) {
            if (! in_array('second_pass_failed', $deduplication, true)) {
                $deduplication[] = 'second_pass_failed';
            }
            $suppressed = true;
            $suppressionReasons = array_values(array_filter([$e::class !== '' ? $e::class : 'runtime_exception']));
        }

        $reporterCount = count($emittedDeliveries);
        $streamWritten = $reporterCount > 0 && $deliveryCount > 0;

        $finalizationState = match (true) {
            $deduplication !== [] && $streamWritten => 'stream_duplication_guarded',
            $streamWritten => 'stream_accepted',
            $suppressed => 'stream_suppressed',
            default => 'stream_unconfirmed',
        };

        return [
            'emitter' => 'quantum.exceptions.occurrence_stream.emulated_persistent',
            'stream_written' => $streamWritten,
            'occurrence_id' => $descriptor->occurrenceId,
            'delivery_ids_by_reporter' => $emittedDeliveries,
            'finalization' => [
                'state' => $finalizationState,
                'reporter_count' => $reporterCount,
                'delivery_count' => $deliveryCount,
                'suppressed' => $suppressed,
                'suppression_reasons' => $suppressionReasons,
            ],
            'deduplication' => $deduplication,
        ];
    }

    /**
     * @return list<string>
     */
    private function reachedLimits(\Quantum\Exceptions\Model\FailureSnapshot $failure): array
    {
        $reached = [];

        if ($failure->truncated) {
            $reached[] = 'snapshot_truncated';
        }

        return $reached;
    }
}
