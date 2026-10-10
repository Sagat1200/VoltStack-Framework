<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Container\Graph\ContainerGraph;
use Quantum\Container\Graph\ContainerGraphInspector;
use Quantum\Container\Graph\ValidationIssue;

final class ContainerDoctorCommand extends Command
{
    public function name(): string
    {
        return 'container:doctor';
    }

    public function description(): string
    {
        return 'Agrupa los issues diagnosticados del contenedor por remediación y sugiere acciones de corrección sin modificar archivos.';
    }

    public function usage(): string
    {
        return 'container:doctor [--strict] [--json] [--code=<code>] [--severity=error|warning|info] [--apply-hint] [--limit=<limit>]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--strict' => 'Devuelve exit code 1 si existen remediaciones pendientes después de aplicar filtros.',
            '--json' => 'Emite un payload JSON estable con el plan de remediación agrupado por acción sugerida.',
            '--code=<code>' => 'Filtra la revisión a un solo código de issue.',
            '--severity=error|warning|info' => 'Filtra la revisión por severidad.',
            '--apply-hint' => 'Emite un “parche sugerido” en formato PHP array para cada remediación (informativo, sin tocar archivos).',
            '--limit=<limit>' => 'Número máximo de servicios por remediación a emitir; 0 desactiva el límite (default 100).',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function ($app) use ($input, $output): int {
            $graph = (new ContainerGraphInspector())->inspect($app);

            $codeFilter = $this->resolveOptionalOption($input, 'code');
            $severityFilter = $this->resolveSeverityOption($input);
            $withHints = $input->hasOption('apply-hint');
            $limit = $this->resolveLimitOption($input);

            $payload = $this->buildPayload($graph, $codeFilter, $severityFilter, $withHints, $limit);

            if ($input->hasOption('json')) {
                $output->writeln((string) json_encode([
                    'command' => $this->name(),
                    'report' => $payload,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->renderHumanReadable($output, $payload);
            }

            if ($input->hasOption('strict') && ($payload['pending_remediation_count'] ?? 0) > 0) {
                return 1;
            }

            return 0;
        });
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

    private function resolveSeverityOption(Input $input): ?string
    {
        $severity = $this->resolveOptionalOption($input, 'severity');

        return match ($severity) {
            'error', 'warning', 'info' => $severity,
            default => null,
        };
    }

    private function resolveLimitOption(Input $input): int
    {
        $raw = $this->resolveOptionalOption($input, 'limit');

        if ($raw === null) {
            return 100;
        }

        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($number === false) {
            return 100;
        }

        return (int) $number;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        ContainerGraph $graph,
        ?string $codeFilter,
        ?string $severityFilter,
        bool $withHints,
        int $limit,
    ): array {
        $issues = array_map(
            function (ValidationIssue $issue): array {
                $payload = $issue->toArray();

                if (! isset($payload['severity'])) {
                    $payload['severity'] = $this->severityForIssueCode($payload['code'] ?? 'unknown');
                }

                return $payload;
            },
            $graph->issues(),
        );

        $matching = array_values(array_filter(
            $issues,
            function (array $issue) use ($codeFilter, $severityFilter): bool {
                if ($codeFilter !== null && ($issue['code'] ?? null) !== $codeFilter) {
                    return false;
                }

                if ($severityFilter !== null) {
                    $issueSeverity = $issue['severity'] ?? $this->severityForIssueCode($issue['code'] ?? 'unknown');
                    if ($issueSeverity !== $severityFilter) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $grouped = [];

        foreach ($matching as $issue) {
            $remediation = (string) ($issue['remediation'] ?? 'Revisar manualmente la definición del servicio.');
            $code = (string) ($issue['code'] ?? 'unknown');
            $severity = (string) ($issue['severity'] ?? 'info');
            $key = $severity . '|' . $code . '|' . $remediation;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'code' => $code,
                    'severity' => $severity,
                    'remediation' => $remediation,
                    'services' => [],
                    'count' => 0,
                    'sample_paths' => [],
                ];
            }

            $grouped[$key]['count']++;

            $serviceId = (string) ($issue['service_id'] ?? ($issue['consumer_id'] ?? 'unknown'));

            if (! in_array($serviceId, $grouped[$key]['services'], true)) {
                $grouped[$key]['services'][] = $serviceId;
            }

            $path = $issue['path'] ?? [];

            if (is_array($path) && $path !== [] && count($grouped[$key]['sample_paths']) < 5) {
                $grouped[$key]['sample_paths'][] = array_values($path);
            }
        }

        foreach ($grouped as $key => $group) {
            $grouped[$key]['truncated_service_count'] = 0;

            if ($limit > 0 && count($group['services']) > $limit) {
                $grouped[$key]['truncated_service_count'] = count($group['services']) - $limit;
                $grouped[$key]['services'] = array_slice($group['services'], 0, $limit);
            }

            if ($withHints) {
                $grouped[$key]['apply_hint'] = $this->buildApplyHint(
                    (string) $group['code'],
                    $grouped[$key]['services'],
                    (string) $group['remediation'],
                );
            }
        }

        usort(
            $grouped,
            static function (array $a, array $b): int {
                $severityOrder = ['error' => 0, 'warning' => 1, 'info' => 2];
                $left = $severityOrder[(string) $a['severity']] ?? 99;
                $right = $severityOrder[(string) $b['severity']] ?? 99;

                if ($left !== $right) {
                    return $left <=> $right;
                }

                return $b['count'] <=> $a['count'];
            },
        );

        $pendingRemediationCount = count($grouped);

        return [
            'filters' => [
                'code' => $codeFilter,
                'severity' => $severityFilter,
            ],
            'issue_count' => count($matching),
            'pending_remediation_count' => $pendingRemediationCount,
            'remediations' => array_values($grouped),
        ];
    }

    /**
     * @param list<string> $services
     * @return array<string, mixed>
     */
    private function buildApplyHint(string $code, array $services, string $remediation): array
    {
        return match ($code) {
            'missing_class' => [
                'intent' => 'registrar o corregir la clase concreta que falta',
                'suggested_php' => array_map(
                    static fn(string $service): string => sprintf(
                        '$app->bind(%s, \Vendor\Concrete\%s::class);',
                        var_export($service, true),
                        ucfirst(str_replace(['.', '-'], '_', $service)),
                    ),
                    $services,
                ),
                'notes' => [
                    'Las clases propuestas son placeholders; reemplazar por la clase real destinada a satisfacer el binding.',
                    $remediation,
                ],
            ],
            'scope_capture_violation' => [
                'intent' => 'sustituir capturas scoped por factories o lookups dentro de scopes request/worker',
                'suggested_php' => array_map(
                    static fn(string $service): string => sprintf(
                        '// Consumidor %s: reemplazar la inyección directa por Container::make() o factory().',
                        var_export($service, true),
                    ),
                    $services,
                ),
                'notes' => [
                    'Nunca retener un servicio scoped en un singleton/largo plazo.',
                    $remediation,
                ],
            ],
            default => [
                'intent' => 'revisar el binding afectado antes de lanzar una corrección',
                'suggested_php' => array_map(
                    static fn(string $service): string => sprintf(
                        '// TODO: revisar servicio %s',
                        var_export($service, true),
                    ),
                    $services,
                ),
                'notes' => [$remediation],
            ],
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

    /**
     * @param array<string, mixed> $payload
     */
    private function renderHumanReadable(Output $output, array $payload): void
    {
        $output->writeln('Container doctor:');

        if (($payload['filters']['code'] ?? null) !== null || ($payload['filters']['severity'] ?? null) !== null) {
            $output->writeln(sprintf(
                '  Filters: code=%s, severity=%s',
                var_export($payload['filters']['code'] ?? null, true),
                var_export($payload['filters']['severity'] ?? null, true),
            ));
        }

        $output->writeln(sprintf('  Issues matched: %d', $payload['issue_count'] ?? 0));
        $output->writeln(sprintf('  Pending remediation groups: %d', $payload['pending_remediation_count'] ?? 0));

        if (($payload['pending_remediation_count'] ?? 0) === 0) {
            $output->writeln('  Plan: no issues matched for the active filters.');

            return;
        }

        $output->writeln('  Remediation plan:');

        foreach (($payload['remediations'] ?? []) as $index => $group) {
            $output->writeln(sprintf(
                '  %d. [%s][%s] %d servicio(s) · %s',
                $index + 1,
                strtoupper((string) $group['severity']),
                (string) $group['code'],
                (int) $group['count'],
                (string) $group['remediation'],
            ));

            $services = $group['services'] ?? [];
            $output->writeln(sprintf(
                '     Services: %s%s',
                implode(', ', array_slice($services, 0, 10)),
                (int) ($group['truncated_service_count'] ?? 0) > 0
                    ? sprintf(' … (+%d truncados)', (int) $group['truncated_service_count'])
                    : '',
            ));

            $samplePaths = $group['sample_paths'] ?? [];

            if ($samplePaths !== []) {
                $output->writeln(sprintf(
                    '     Sample paths: %s',
                    implode(' / ', array_map(
                        static fn(array $path): string => implode(' -> ', $path),
                        $samplePaths,
                    )),
                ));
            }

            if (isset($group['apply_hint'])) {
                $hint = $group['apply_hint'];
                $output->writeln(sprintf('     Hint intent: %s', (string) ($hint['intent'] ?? '')));

                foreach (array_slice((array) ($hint['suggested_php'] ?? []), 0, 3) as $line) {
                    $output->writeln('     · ' . $line);
                }
            }
        }
    }
}
