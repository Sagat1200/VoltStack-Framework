<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use RuntimeException;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidencePublisher;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;
use VoltStack\Runtime\Telemetry\RuntimeCapabilityEvidenceTelemetryEmitter;

/**
 * Comando de ingesta de evidencia runtime.
 *
 * Caso de uso pensado: al acabar de correr checks reales contra FrankenPHP,
 * RoadRunner o OpenSwoole nativos, se emite un JSON con el reporte de
 * verificación y se ingresa aquí. Este comando publica y opcionalmente
 * activa la evidence generation, para que runtime:status y
 * runtime:release-pipeline la consuman.
 */
final class RuntimeEvidenceIngestCommand extends Command
{
    public function name(): string
    {
        return 'runtime:evidence-ingest';
    }

    public function description(): string
    {
        return 'Ingresa un reporte de verificación runtime y lo publica/activa como evidencia operativa por driver/platform.';
    }

    public function usage(): string
    {
        return 'runtime:evidence-ingest [--driver=frankenphp] [--platform=linux-frankenphp-prod] [--profile=release] [--evidence-dir=storage/framework/runtime-evidence] [--input=/path/to/report.json] [--json-string="{...}"] [--activate] [--require-published-config] [--emit-telemetry] [--json]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime (si no se especifica, se lee del payload JSON).',
            '--platform=' => 'Identificador de plataforma (si no se especifica, se lee del payload JSON).',
            '--profile=' => 'Profile operativo asociado a la verificación (default: release).',
            '--evidence-dir=' => 'Directorio base donde se publica evidencia runtime por driver.',
            '--input=' => 'Ruta a fichero JSON con el payload del reporte de verificación.',
            '--json-string=' => 'Payload JSON inline con el reporte de verificación.',
            '--activate' => 'Activa la generation publicada tras publicar la evidencia.',
            '--require-published-config' => 'Exige una generation config activa y sin drift antes de ingerir.',
            '--emit-telemetry' => 'Emite telemetry con el reporte de verificación y el resultado de publicación.',
            '--json' => 'Emite un payload JSON estable con el resultado de la ingesta.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $report = $this->buildReport($input);
        $evidenceBaseDirectory = is_string($input->option('evidence-dir')) ? $input->option('evidence-dir') : null;
        $activate = $input->hasOption('activate');

        $publishedArtifact = null;
        $activated = false;
        $publishError = null;

        try {
            $publishedArtifact = $this->runInCommandRuntime(function ($app) use ($report, $evidenceBaseDirectory, $activate) {
                if ($app->configStatusInspector() !== null) {
                    // no-op solo para que el linter no se queje de uso no uniforme
                }

                $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
                $artifact = $publisher->publish(
                    report: $report,
                    app: $app,
                    evidenceBaseDirectory: $evidenceBaseDirectory,
                );

                if ($activate) {
                    $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver(
                        app: $app,
                        driver: $report->driver(),
                        baseDirectory: $evidenceBaseDirectory,
                    );
                    $store->activateGeneration($artifact->generationId());
                }

                return $artifact;
            }, requirePublishedConfig: $input->hasOption('require-published-config'));

            if ($activate && $publishedArtifact !== null) {
                $activated = true;
            }
        } catch (RuntimeException $e) {
            $publishError = $e->getMessage();
        }

        if ($input->hasOption('emit-telemetry')) {
            $this->runInCommandRuntime(function ($app) use ($report, $publishedArtifact): void {
                (new RuntimeCapabilityEvidenceTelemetryEmitter(
                    $app->make(TelemetryManagerInterface::class),
                ))->emitIngest($report, $publishedArtifact !== null);
            }, requirePublishedConfig: $input->hasOption('require-published-config'));
        }

        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);
        $metadata = $report->metadata();
        $source = (is_string($metadata['source'] ?? null) && trim($metadata['source']) !== '')
            ? trim($metadata['source'])
            : 'unknown';

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'activated' => $activated,
                'published' => $publishedArtifact !== null,
                'published_artifact' => $publishedArtifact?->toArray(),
                'publish_error' => $publishError,
                'report' => $report->toArray(),
                'source' => $source,
                'catalog_audit' => [
                    'unknown_check_ids' => $audit['unknown_ids'],
                    'missing_required_check_ids' => $audit['missing_required_ids'],
                    'failed_required_check_ids' => $audit['failed_required_ids'],
                ],
            ];

            $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln('Runtime capability evidence ingest:');
            $output->writeln(sprintf('  Driver: %s', $report->driver()));
            $output->writeln(sprintf('  Platform: %s', $report->platform()));
            $output->writeln(sprintf('  Profile: %s', $report->profile()));
            $output->writeln(sprintf(
                '  Checks: %d/%d passed',
                $report->passedChecks(),
                $report->totalChecks(),
            ));
            $output->writeln(sprintf(
                '  Valid: %s',
                $report->valid() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Proves native integration: %s',
                $report->provesNativeIntegration() ? 'yes' : 'no',
            ));
            $output->writeln(sprintf(
                '  Evidence level: %s',
                $report->evidenceLevel(),
            ));

            if ($publishedArtifact !== null) {
                $output->writeln(sprintf(
                    '  Published generation: %s',
                    $publishedArtifact->generationId(),
                ));
                $output->writeln(sprintf(
                    '  Published manifest: %s',
                    $publishedArtifact->manifestPath(),
                ));
                $output->writeln(sprintf(
                    '  Activated: %s',
                    $activated ? 'yes' : 'no',
                ));
            } elseif ($publishError !== null) {
                $output->writeln(sprintf('  Publish error: %s', $publishError));
            } else {
                $output->writeln('  Published: no');
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }
        }

        if ($publishError !== null || ! $report->valid()) {
            return 1;
        }

        return 0;
    }

    private function buildReport(Input $input): RuntimeCapabilityVerificationReport
    {
        $payload = $this->readPayload($input);

        $driverOption = is_string($input->option('driver')) ? $input->option('driver') : null;
        $platformOption = is_string($input->option('platform')) ? $input->option('platform') : null;
        $profileOption = is_string($input->option('profile')) ? $input->option('profile') : null;

        if ($driverOption !== null && trim($driverOption) !== '') {
            $payload['driver'] = $driverOption;
        }

        if ($platformOption !== null && trim($platformOption) !== '') {
            $payload['platform'] = $platformOption;
        }

        if ($profileOption !== null && trim($profileOption) !== '') {
            $payload['profile'] = $profileOption;
        }

        return RuntimeCapabilityVerificationReport::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(Input $input): array
    {
        $inputPath = is_string($input->option('input')) ? $input->option('input') : null;
        $inline = is_string($input->option('json-string')) ? $input->option('json-string') : null;

        $raw = null;

        if ($inline !== null && trim($inline) !== '') {
            $raw = $inline;
        } elseif ($inputPath !== null && trim($inputPath) !== '') {
            if (! is_file($inputPath)) {
                throw new RuntimeException(sprintf(
                    'Runtime evidence ingest input file [%s] does not exist.',
                    $inputPath,
                ));
            }

            $contents = @file_get_contents($inputPath);

            if ($contents === false) {
                throw new RuntimeException(sprintf(
                    'Runtime evidence ingest input file [%s] could not be read.',
                    $inputPath,
                ));
            }

            $raw = $contents;
        }

        if ($raw === null || trim($raw) === '') {
            throw new RuntimeException(
                'Runtime evidence ingest requires --input=/path/to/report.json or --json-string="..." with a valid verification report payload.',
            );
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Runtime evidence ingest payload is not a valid JSON object.',
            );
        }

        return $decoded;
    }
}
