<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Exceptions\Catalog\SemanticErrorCatalog;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Diagnostics\ExceptionCatalogReport;
use VoltStack\Framework\Application;

final class ExceptionCatalogCommand extends Command
{
    public function name(): string
    {
        return 'exceptions:catalog';
    }

    public function description(): string
    {
        return 'Lista el catalogo semantico efectivo del subsistema y sus codigos ignorados por reporting.';
    }

    public function usage(): string
    {
        return 'exceptions:catalog [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--json' => 'Emite un payload JSON estable con el catalogo efectivo.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        return $this->runInCommandRuntime(function (Application $app) use ($input, $output): int {
            $rawConfig = $app->config('exceptions', []);
            $config = is_array($rawConfig) ? $rawConfig : [];

            try {
                $plan = $app->make(ExceptionPlanCompiler::class)->compile($config);
                $ignoredCodes = $plan->config()['reporting']['ignore_codes'] ?? [];
                $catalog = SemanticErrorCatalog::defaults()->all();
                ksort($catalog);

                $report = new ExceptionCatalogReport(
                    plan: $plan,
                    entries: array_values($catalog),
                    ignoredCodes: is_array($ignoredCodes) ? array_values($ignoredCodes) : [],
                );
            } catch (ExceptionCompilationException $exception) {
                $report = null;
                $error = $exception->getMessage();
            }

            if ($input->hasOption('json')) {
                $payload = [
                    'command' => $this->name(),
                    'report' => $report?->toArray(),
                    'error' => $error ?? null,
                ];

                $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('Exceptions catalog:');

                if ($report === null) {
                    $output->writeln(sprintf('  Error: %s', $error ?? 'unknown'));

                    return 1;
                }

                $payload = $report->toArray();
                $effective = $payload['effective'];
                $summary = $payload['summary'];

                $output->writeln(sprintf('  Environment: %s', $effective['environment']));
                $output->writeln(sprintf('  Runtime: %s', $effective['runtime']));
                $output->writeln(sprintf('  Fingerprint: %s', $effective['fingerprint']));
                $output->writeln(sprintf('  Entries: %d', $summary['catalog_entries']));
                $output->writeln(sprintf('  Ignored by reporting: %d', $summary['ignored_by_reporting']));
                $output->writeln('  Catalog:');

                foreach ($payload['entries'] as $entry) {
                    $output->writeln(sprintf(
                        '    - %s [%s] severity=%s retry=%s http=%s cli=%s ignored=%s',
                        $entry['code'],
                        $entry['category'],
                        $entry['severity'],
                        $entry['retry_advice'],
                        $entry['http_default'] ?? '-',
                        $entry['cli_default'] ?? '-',
                        $entry['ignored_by_reporting'] ? 'yes' : 'no',
                    ));
                }
            }

            return $report === null ? 1 : 0;
        });
    }
}
