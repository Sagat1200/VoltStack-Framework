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
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Core\ExceptionDescriptorFactory;
use Quantum\Exceptions\Core\PublicErrorProjector;
use Quantum\Exceptions\Diagnostics\ExceptionExplainReport;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Reporting\ExceptionReportingPolicy;
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
        return 'exceptions:explain [--type=validation] [--transport=json] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--type=' => 'Fixture sintetico: validation, auth_required, auth_failed, throttled, not_found, db_retryable, db_default, config_invalid, internal.',
            '--transport=' => 'Superficie sintetica: json, html, spa, cli o cli-json.',
            '--json' => 'Emite un payload JSON estable con la explicacion.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function (Application $app) use ($input, $output): int {
            try {
                $type = $this->stringOption($input, 'type') ?? 'validation';
                $transport = $this->stringOption($input, 'transport') ?? 'json';
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
                    ignoredByPolicy: $this->ignoredByPolicy($app, $descriptor),
                    reachedLimits: $this->reachedLimits($failure),
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
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown explain fixture [%s]. Supported fixtures: validation, auth_required, auth_failed, throttled, not_found, db_retryable, db_default, config_invalid, internal.',
                $type,
            )),
        };

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

    private function ignoredByPolicy(Application $app, \Quantum\Exceptions\Model\ExceptionDescriptor $descriptor): bool
    {
        $policy = $app->make(ExceptionReportingPolicy::class);
        $record = new ReportRecord(
            occurrenceId: $descriptor->occurrenceId,
            parentOccurrenceId: null,
            fingerprint: $descriptor->policyRevision,
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

        return ! $decision->shouldReport;
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
