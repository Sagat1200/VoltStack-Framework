<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Container\Diagnostics\ContainerStatusReport;
use Quantum\Container\Graph\ContainerGraph;
use Quantum\Container\Graph\ContainerGraphInspector;
use Quantum\Container\Graph\ValidationIssue;

final class ContainerStatusCommand extends Command
{
    public function name(): string
    {
        return 'container:status';
    }

    public function description(): string
    {
        return 'Inspecciona el grafo estatico del contenedor y reporta bindings, dependencias y problemas diagnosticados.';
    }

    public function usage(): string
    {
        return 'container:status [--strict] [--json] [--code=<code>] [--service=<service>] [--severity=error|warning|info] [--format=brief|full|graph] [--issue-limit=<limit>] [--require-published-config]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--strict' => 'Devuelve exit code 1 si el diagnostico contiene issues (considerando filtros activos).',
            '--json' => 'Emite un payload JSON estable con el reporte de status del contenedor.',
            '--code=<code>' => 'Filtra issues por codigo diagnostico (p.ej. missing_class, scope_capture_violation).',
            '--service=<service>' => 'Filtra issues por id de servicio (consumer o service_id del issue).',
            '--severity=error|warning|info' => 'Filtra issues por severidad (error: missing_class/not_instantiable/dependency_cycle, warning: scope_capture_violation, info: unresolvable_parameter/unresolvable_dependency).',
            '--format=brief|full|graph' => 'Controla la emision: brief solo emite resumen; full incluye issues y graph; graph solo emite graph mas conteo.',
            '--issue-limit=<limit>' => 'Trunca la lista de issues emitidos al valor dado; 0 desactiva el limite (default 250).',
            '--require-published-config' => 'Exige una generacion de configuracion publicada activa y sin drift antes de inspeccionar el container.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function ($app) use ($input, $output): int {
            $graph = (new ContainerGraphInspector())->inspect($app);

            $codeFilter = $this->resolveOptionalOption($input, 'code');
            $serviceFilter = $this->resolveOptionalOption($input, 'service');
            $severityFilter = $this->resolveSeverityOption($input);
            $format = $this->resolveFormatOption($input);
            $issueLimit = $this->resolveIssueLimitOption($input);

            $report = $this->buildReport($graph, $codeFilter, $serviceFilter, $severityFilter, $format, $issueLimit);

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report->toArray(),
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->renderHumanReadable($output, $report);
            }

            if ($input->hasOption('strict') && ! $report->healthyUnderFilter()) {
                return 1;
            }

            return 0;
        }, requirePublishedConfig: $input->hasOption('require-published-config'));
    }

    private function resolveOptionalOption(Input $input, string $name): ?string
    {
        if (! $input->hasOption($name)) {
            return null;
        }

        $value = $input->option($name);

        if ($value === null || $value === '' || $value === true) {
            return null;
        }

        return (string) $value;
    }

    private function resolveFormatOption(Input $input): string
    {
        $format = $this->resolveOptionalOption($input, 'format');

        return match ($format) {
            'brief', 'full', 'graph' => $format,
            default => 'full',
        };
    }

    private function resolveSeverityOption(Input $input): ?string
    {
        $severity = $this->resolveOptionalOption($input, 'severity');

        return match ($severity) {
            'error', 'warning', 'info' => $severity,
            default => null,
        };
    }

    /**
     * @phpstan-return 'error'|'warning'|'info'
     */
    private function severityForIssueCode(string $code): string
    {
        return match ($code) {
            'missing_class', 'not_instantiable', 'dependency_cycle' => 'error',
            'scope_capture_violation' => 'warning',
            default => 'info',
        };
    }

    private function resolveIssueLimitOption(Input $input): int
    {
        $raw = $this->resolveOptionalOption($input, 'issue-limit');

        if ($raw === null) {
            return 250;
        }

        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($number === false) {
            return 250;
        }

        return (int) $number;
    }

    private function buildReport(
        ContainerGraph $graph,
        ?string $codeFilter,
        ?string $serviceFilter,
        ?string $severityFilter,
        string $format,
        int $issueLimit,
    ): ContainerStatusReport {
        $serializedIssues = array_map(
            static function (ValidationIssue $issue): array {
                $payload = $issue->toArray();

                if (! isset($payload['severity'])) {
                    $payload['severity'] = (new self($this->basePath))->severityForIssueCode($payload['code'] ?? 'unknown');
                }

                return $payload;
            },
            $graph->issues(),
        );

        $filteredIssues = array_values(array_filter(
            $serializedIssues,
            function (array $issue) use ($codeFilter, $serviceFilter, $severityFilter): bool {
                if ($codeFilter !== null && ($issue['code'] ?? null) !== $codeFilter) {
                    return false;
                }

                if ($severityFilter !== null) {
                    $issueSeverity = $issue['severity'] ?? $this->severityForIssueCode($issue['code'] ?? 'unknown');
                    if ($issueSeverity !== $severityFilter) {
                        return false;
                    }
                }

                if ($serviceFilter !== null) {
                    $serviceId = $issue['service_id'] ?? null;
                    $consumerId = $issue['consumer_id'] ?? null;

                    if ($serviceId !== $serviceFilter && $consumerId !== $serviceFilter) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $appliedIssueLimit = $issueLimit;
        $truncatedIssueCount = 0;

        if ($appliedIssueLimit > 0 && count($filteredIssues) > $appliedIssueLimit) {
            $truncatedIssueCount = count($filteredIssues) - $appliedIssueLimit;
            $filteredIssues = array_slice($filteredIssues, 0, $appliedIssueLimit);
        }

        return new ContainerStatusReport(
            serviceCount: count($graph->services()),
            aliasCount: count($graph->aliases()),
            analyzableServiceCount: $this->countAnalyzableServices($graph),
            analyzableConcreteClassCount: $this->countConcreteAnalyzableClassBindings($graph),
            closureBindingCount: $this->countClosureBindings($graph),
            singletonCount: $this->countLifetime($graph, 'singleton'),
            scopedCount: $this->countLifetime($graph, 'scoped'),
            transientCount: $this->countLifetime($graph, 'transient'),
            instanceBindingCount: $this->countInstanceBindings($graph),
            issueCount: count($graph->issues()),
            missingClassCount: $this->countIssuesByCode($graph, 'missing_class'),
            notInstantiableCount: $this->countIssuesByCode($graph, 'not_instantiable'),
            unresolvableParameterCount: $this->countIssuesByCode($graph, 'unresolvable_parameter'),
            unresolvableDependencyCount: $this->countIssuesByCode($graph, 'unresolvable_dependency'),
            dependencyCycleCount: $this->countIssuesByCode($graph, 'dependency_cycle'),
            scopeCaptureViolationCount: $this->countIssuesByCode($graph, 'scope_capture_violation'),
            issues: $serializedIssues,
            filteredIssues: $filteredIssues,
            graph: $graph->toArray(),
            filteredIssueCount: count($filteredIssues) + $truncatedIssueCount,
            truncatedIssueCount: $truncatedIssueCount,
            appliedIssueLimit: $appliedIssueLimit,
            codeFilter: $codeFilter,
            serviceFilter: $serviceFilter,
            severityFilter: $severityFilter,
            format: $format,
        );
    }

    /**
     * @param ContainerGraph $graph
     * @return array<string, int>
     */
    private function lifetimeBreakdown(ContainerGraph $graph): array
    {
        return [
            'singleton' => $this->countLifetime($graph, 'singleton'),
            'scoped' => $this->countLifetime($graph, 'scoped'),
            'transient' => $this->countLifetime($graph, 'transient'),
        ];
    }

    private function renderHumanReadable(Output $output, ContainerStatusReport $report): void
    {
        $lifetimes = $report->lifetime_breakdown;

        $output->writeln('Container status:');
        $output->writeln(sprintf('  Format: %s', $report->format));

        if ($report->isFiltered()) {
            $codeLabel = $report->codeFilter ?? '(none)';
            $serviceLabel = $report->serviceFilter ?? '(none)';
            $severityLabel = $report->severityFilter ?? '(none)';
            $output->writeln(sprintf('  Filters: code=%s, service=%s, severity=%s', $codeLabel, $serviceLabel, $severityLabel));
        }

        $output->writeln(sprintf('  Services: %d', $report->serviceCount));
        $output->writeln(sprintf('  Aliases: %d', $report->aliasCount));
        $output->writeln(sprintf('  Analyzable services: %d', $report->analyzableServiceCount));
        $output->writeln(sprintf(
            '  Lifetimes: singleton=%d, scoped=%d, transient=%d',
            $lifetimes['singleton'],
            $lifetimes['scoped'],
            $lifetimes['transient'],
        ));
        $output->writeln(sprintf(
            '  Closure bindings: %d',
            $report->closureBindingCount,
        ));
        $output->writeln(sprintf(
            '  Instance bindings: %d',
            $report->instanceBindingCount,
        ));
        $output->writeln(sprintf('  Issues: %d (matched %d)', $report->issueCount, $report->filteredIssueCount));

        if ($report->truncatedIssueCount > 0) {
            $output->writeln(sprintf(
                '  Truncated issues: %d (limit %d)',
                $report->truncatedIssueCount,
                $report->appliedIssueLimit,
            ));
        }

        if ($report->format === 'brief') {
            return;
        }

        if ($report->filteredIssueCount === 0) {
            if ($report->format === 'graph') {
                $output->writeln(sprintf(
                    '  Graph snapshot: services=%d, aliases=%d, has_issues=%s',
                    count($report->graph['services'] ?? []),
                    count($report->graph['aliases'] ?? []),
                    ($report->graph['has_issues'] ?? false) ? 'yes' : 'no',
                ));
            }

            return;
        }

        $output->writeln('  Issue breakdown:');
        foreach ([
            'missing_class' => 'Missing class',
            'not_instantiable' => 'Non instantiable target',
            'unresolvable_parameter' => 'Unresolvable parameter',
            'unresolvable_dependency' => 'Unresolvable dependency',
            'dependency_cycle' => 'Dependency cycle',
            'scope_capture_violation' => 'Scope capture violation',
        ] as $code => $label) {
            $count = $this->countFilteredIssuesByCode($report, $code);

            if ($count === 0) {
                continue;
            }

            $output->writeln(sprintf('    - %s: %d', $label, $count));
        }

        if ($report->format !== 'graph') {
            $output->writeln('  Issues:');
            foreach ($report->filteredIssues as $issue) {
                $path = $issue['path'] ?? [];
                $output->writeln(sprintf(
                    '    - [%s][%s] %s %s',
                    $issue['code'] ?? 'unknown',
                    $issue['phase'] ?? 'unknown',
                    $issue['message'] ?? '',
                    $path !== [] && $path !== null
                        ? sprintf('(path: %s)', implode(' -> ', $path))
                        : '',
                ));
            }

            return;
        }

        $output->writeln(sprintf(
            '  Graph snapshot: services=%d, aliases=%d, has_issues=%s',
            count($report->graph['services'] ?? []),
            count($report->graph['aliases'] ?? []),
            ($report->graph['has_issues'] ?? false) ? 'yes' : 'no',
        ));
    }

    private function countFilteredIssuesByCode(ContainerStatusReport $report, string $code): int
    {
        $count = 0;

        foreach ($report->filteredIssues as $issue) {
            if (($issue['code'] ?? null) === $code) {
                $count++;
            }
        }

        return $count;
    }

    private function countAnalyzableServices(ContainerGraph $graph): int
    {
        $count = 0;

        foreach ($graph->services() as $service) {
            if ($service->analyzable) {
                $count++;
            }
        }

        return $count;
    }

    private function countConcreteAnalyzableClassBindings(ContainerGraph $graph): int
    {
        $count = 0;

        foreach ($graph->services() as $service) {
            if ($service->analyzable && $service->concreteKind === 'class') {
                $count++;
            }
        }

        return $count;
    }

    private function countClosureBindings(ContainerGraph $graph): int
    {
        $count = 0;

        foreach ($graph->services() as $service) {
            if ($service->concreteKind === 'closure') {
                $count++;
            }
        }

        return $count;
    }

    private function countInstanceBindings(ContainerGraph $graph): int
    {
        $count = 0;

        foreach ($graph->services() as $service) {
            if ($service->concreteKind === 'object') {
                $count++;
            }
        }

        return $count;
    }

    private function countLifetime(ContainerGraph $graph, string $lifetime): int
    {
        $count = 0;

        foreach ($graph->services() as $service) {
            if ($service->lifetime === $lifetime) {
                $count++;
            }
        }

        return $count;
    }

    private function countIssuesByCode(ContainerGraph $graph, string $code): int
    {
        $count = 0;

        foreach ($graph->issues() as $issue) {
            if ($issue->code === $code) {
                $count++;
            }
        }

        return $count;
    }
}
