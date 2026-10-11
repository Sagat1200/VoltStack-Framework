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
use VoltStack\Runtime\Evidence\RuntimeCheckSuiteRunner;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;
use VoltStack\Runtime\Telemetry\RuntimeCapabilityEvidenceTelemetryEmitter;

/**
 * Comando CLI que ejecuta un check-suite de runner real desde PHP.
 *
 * Realiza dos comprobaciones externas (smoke HTTP + API PHP nativa del runner)
 * y delega en {@see RuntimeCheckSuiteRunner} la construcción del report JSON
 * compatible con {@see RuntimeCapabilityVerificationReport::fromArray()},
 * evitando la necesidad de un script PS1 intermedio.
 *
 * Honestidad operativa: si el runner no está disponible (sin servidor HTTP
 * accesible o sin extensión/API PHP), el comando NO inventa resultados: marca
 * las comprobaciones como fallidas y produce un report con los checks required
 * en `failed`, para que `runtime:evidence-ingest` y `runtime:status --strict-rollout`
 * lo detecten correctamente.
 */
final class RuntimeCheckSuiteCommand extends Command
{
    /**
     * Drivers soportados y su fingerprint HTTP nativo.
     *
     * @var array<string, string>
     */
    private const HEADER_FINGERPRINTS = [
        'frankenphp' => 'X-Powered-By: FrankenPHP',
        'roadrunner' => 'Server: RoadRunner',
        'openswoole' => 'Server: swoole-http-server',
        'sapi' => '',
    ];

    public function name(): string
    {
        return 'runtime:check-suite';
    }

    public function description(): string
    {
        return 'Ejecuta un check-suite de runner real (smoke HTTP + API PHP nativa) y emite un report JSON consumible por runtime:evidence-ingest.';
    }

    public function usage(): string
    {
        return 'runtime:check-suite --driver=frankenphp [--server-url=http://127.0.0.1:8080] [--smoke-path=/ok] [--platform=win-frankenphp-x64] [--profile=release] [--timeout-sec=30] [--output=storage/framework/runtime-evidence/report.json] [--json] [--ingest] [--activate] [--emit-telemetry]';
    }

    public function category(): string
    {
        return 'Runtime';
    }

    public function optionsHelp(): array
    {
        return [
            '--driver=' => 'Driver runtime (frankenphp|roadrunner|openswoole|sapi).',
            '--server-url=' => 'URL base del runner en ejecución (ej: http://127.0.0.1:8080).',
            '--smoke-path=' => 'Ruta del endpoint de smoke (default: /ok).',
            '--platform=' => 'Identificador de plataforma (default: auto-detectado desde PHP_OS).',
            '--profile=' => 'Perfil de evidencia (default: release).',
            '--timeout-sec=' => 'Timeout del smoke HTTP en segundos (default: 30).',
            '--output=' => 'Ruta donde escribir el report JSON.',
            '--json' => 'Emite el report JSON por stdout.',
            '--ingest' => 'Ingiere automáticamente el report via runtime:evidence-ingest (publica evidencia).',
            '--activate' => 'Activa la generation publicada tras ingerir (implica --ingest).',
            '--emit-telemetry' => 'Emite telemetry con el report de verificación.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $driver = $this->resolveDriver($input);
        $serverUrl = is_string($input->option('server-url')) ? rtrim($input->option('server-url'), '/') : null;
        $smokePath = is_string($input->option('smoke-path')) && trim($input->option('smoke-path')) !== ''
            ? $input->option('smoke-path')
            : '/ok';
        $platform = is_string($input->option('platform')) && trim($input->option('platform')) !== ''
            ? $input->option('platform')
            : $this->defaultPlatform($driver);
        $profile = is_string($input->option('profile')) && trim($input->option('profile')) !== ''
            ? $input->option('profile')
            : 'release';
        $timeoutSec = $this->intOption($input, 'timeout-sec', 30);

        // 1. Smoke HTTP real contra el runner.
        $smokeResult = $this->runSmokeHttp($driver, $serverUrl, $smokePath, $timeoutSec);
        $smokePassed = $smokeResult['passed'];
        $smokeMetadata = $smokeResult['metadata'];

        // 2. API PHP nativa del runner.
        $nativeApiPassed = $this->checkNativePhpApi($driver);

        // 3. Construir report via RuntimeCheckSuiteRunner (catálogo como fuente de verdad).
        $runnerVersion = $smokeMetadata['runner_version'] ?? 'unknown';

        $report = RuntimeCheckSuiteRunner::buildReport(
            driver: $driver,
            platform: $platform,
            profile: $profile,
            runnerVersion: $runnerVersion,
            smokePassed: $smokePassed,
            nativeApiPassed: $nativeApiPassed,
            smokeMetadata: $smokeMetadata,
        );

        // 4. Escribir a fichero si --output.
        $outputPath = is_string($input->option('output')) ? $input->option('output') : null;
        if ($outputPath !== null && trim($outputPath) !== '') {
            $this->writeReportToFile($report, $outputPath);
        }

        // 5. Ingesta opcional (publica + activa evidencia).
        $ingest = $input->hasOption('ingest') || $input->hasOption('activate');
        $activate = $input->hasOption('activate');
        $publishedArtifact = null;
        $activated = false;

        if ($ingest) {
            try {
                $publishedArtifact = $this->runInCommandRuntime(function ($app) use ($report, $activate) {
                    $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
                    $artifact = $publisher->publish(report: $report, app: $app);

                    if ($activate) {
                        $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver(
                            app: $app,
                            driver: $report->driver(),
                        );
                        $store->activateGeneration($artifact->generationId());
                    }

                    return $artifact;
                });

                if ($activate && $publishedArtifact !== null) {
                    $activated = true;
                }
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>Ingest failed: %s</error>', $e->getMessage()));
            }
        }

        // 6. Telemetría opcional.
        if ($input->hasOption('emit-telemetry')) {
            $this->runInCommandRuntime(function ($app) use ($report, $publishedArtifact): void {
                (new RuntimeCapabilityEvidenceTelemetryEmitter(
                    $app->make(TelemetryManagerInterface::class),
                ))->emitIngest($report, $publishedArtifact !== null);
            });
        }

        // 7. Salida.
        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);
        $metadata = $report->metadata();
        $source = (is_string($metadata['source'] ?? null) && trim($metadata['source']) !== '')
            ? trim($metadata['source'])
            : 'unknown';

        if ($input->hasOption('json')) {
            $payload = [
                'command' => $this->name(),
                'driver' => $driver,
                'platform' => $platform,
                'profile' => $profile,
                'smoke_passed' => $smokePassed,
                'native_api_passed' => $nativeApiPassed,
                'telemetry_emitted' => $input->hasOption('emit-telemetry'),
                'ingested' => $ingest,
                'activated' => $activated,
                'published' => $publishedArtifact !== null,
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
            $output->writeln('Runtime check-suite:');
            $output->writeln(sprintf('  Driver: %s', $driver));
            $output->writeln(sprintf('  Platform: %s', $platform));
            $output->writeln(sprintf('  Smoke HTTP: %s', $smokePassed ? 'passed' : 'failed'));
            $output->writeln(sprintf('  Native PHP API: %s', $nativeApiPassed ? 'available' : 'not available'));
            $output->writeln(sprintf(
                '  Checks: %d/%d passed',
                $report->passedChecks(),
                $report->totalChecks(),
            ));
            $output->writeln(sprintf('  Valid: %s', $report->valid() ? 'yes' : 'no'));
            $output->writeln(sprintf('  Evidence level: %s', $report->evidenceLevel()));

            if ($outputPath !== null) {
                $output->writeln(sprintf('  Report written to: %s', $outputPath));
            }

            if ($publishedArtifact !== null) {
                $output->writeln(sprintf('  Published generation: %s', $publishedArtifact->generationId()));
                $output->writeln(sprintf('  Activated: %s', $activated ? 'yes' : 'no'));
            }

            if ($input->hasOption('emit-telemetry')) {
                $output->writeln('  Telemetry: emitted');
            }

            $output->writeln('');
            $output->writeln('Suggested next step:');
            $output->writeln(sprintf(
                '  vendor/bin/volt runtime:evidence-ingest --input=%s --activate --emit-telemetry --json',
                $outputPath ?? '<ruta del report>',
            ));
        }

        // Exit code: 0 si el report es válido (todos los checks pasaron), 1 si hubo fallos.
        return $report->valid() ? 0 : 1;
    }

    private function resolveDriver(Input $input): string
    {
        $driver = is_string($input->option('driver')) ? strtolower(trim($input->option('driver'))) : '';

        if ($driver === '') {
            throw new RuntimeException('runtime:check-suite requires --driver=frankenphp|roadrunner|openswoole|sapi.');
        }

        if (! isset(self::HEADER_FINGERPRINTS[$driver])) {
            throw new RuntimeException(sprintf(
                'Unsupported driver [%s]. Supported drivers: frankenphp, roadrunner, openswoole, sapi.',
                $driver,
            ));
        }

        return $driver;
    }

    private function defaultPlatform(string $driver): string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        return sprintf('%s-%s-%s', $os, $driver, $arch);
    }

    private function intOption(Input $input, string $name, int $default): int
    {
        $value = $input->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        $numeric = (int) $value;

        return $numeric > 0 ? $numeric : $default;
    }

    /**
     * Ejecuta el smoke HTTP contra el runner y devuelve el resultado.
     *
     * @return array{passed: bool, metadata: array<string, mixed>}
     */
    private function runSmokeHttp(string $driver, ?string $serverUrl, string $smokePath, int $timeoutSec): array
    {
        $metadata = [
            'smoke_http_passed' => false,
            'smoke_http_status' => null,
            'smoke_http_duration_ms' => null,
            'smoke_http_error' => null,
            'runner_version' => 'unknown',
        ];

        if ($serverUrl === null || trim($serverUrl) === '') {
            $metadata['smoke_http_error'] = 'No --server-url provided; smoke HTTP skipped.';

            return ['passed' => false, 'metadata' => $metadata];
        }

        $url = $serverUrl . $smokePath;
        $startTime = microtime(true);

        [$statusCode, $headers, $error] = $this->httpGet($url, $timeoutSec);

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $metadata['smoke_http_duration_ms'] = $durationMs;

        if ($error !== null) {
            $metadata['smoke_http_error'] = $error;

            return ['passed' => false, 'metadata' => $metadata];
        }

        $metadata['smoke_http_status'] = $statusCode;

        if ($statusCode !== 200) {
            $metadata['smoke_http_error'] = sprintf('Unexpected HTTP status %d (expected 200).', $statusCode);

            return ['passed' => false, 'metadata' => $metadata];
        }

        // Verificar fingerprint nativo del driver.
        $fingerprint = self::HEADER_FINGERPRINTS[$driver] ?? '';
        if ($fingerprint !== '') {
            $headerMatched = $this->matchFingerprint($headers, $fingerprint);
            if (! $headerMatched) {
                $metadata['smoke_http_error'] = sprintf(
                    'Native fingerprint header [%s] not found in response.',
                    $fingerprint,
                );

                return ['passed' => false, 'metadata' => $metadata];
            }
        }

        // Intentar detectar versión del runner desde headers.
        $metadata['runner_version'] = $this->detectRunnerVersion($driver, $headers);

        $metadata['smoke_http_passed'] = true;

        return ['passed' => true, 'metadata' => $metadata];
    }

    /**
     * Realiza una petición HTTP GET y devuelve status code, headers y error.
     *
     * @return array{0: int|null, 1: array<string, string>, 2: string|null}
     */
    private function httpGet(string $url, int $timeoutSec): array
    {
        $headers = [];
        $statusCode = null;
        $error = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => min($timeoutSec, 10),
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);

                return [null, [], $error];
            }

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($response, 0, $headerSize);
            curl_close($ch);

            $headers = $this->parseHeaders($rawHeaders);
        } else {
            // Fallback a file_get_contents con stream context.
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeoutSec,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                $error = 'HTTP request failed (file_get_contents).';

                return [null, [], $error];
            }

            // $http_response_header es una variable superglobal mágica de PHP.
            if (isset($http_response_header) && is_array($http_response_header)) {
                $headers = $this->parseHeaders(implode("\r\n", $http_response_header));
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0] ?? '', $matches)) {
                    $statusCode = (int) $matches[1];
                }
            }
        }

        return [$statusCode, $headers, $error];
    }

    /**
     * @param array<string, string> $headers
     */
    private function matchFingerprint(array $headers, string $fingerprint): bool
    {
        // El fingerprint tiene formato "Header-Name: Value".
        $parts = explode(':', $fingerprint, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);

        foreach ($headers as $headerName => $headerValue) {
            if (strtolower($headerName) === $name) {
                // Match case-insensitive parcial (el valor puede tener parámetros).
                return stripos($headerValue, $value) !== false;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private function detectRunnerVersion(string $driver, array $headers): string
    {
        foreach ($headers as $name => $value) {
            $lowerName = strtolower($name);
            if ($lowerName === 'x-powered-by' || $lowerName === 'server') {
                // Extraer versión de strings como "FrankenPHP/1.2.3" o "RoadRunner/2024.1".
                if (preg_match('/\/([0-9][0-9a-zA-Z.\-]*)/', $value, $matches)) {
                    return $matches[1];
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];

        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (trim($line) === '' || strpos($line, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }

    private function checkNativePhpApi(string $driver): bool
    {
        return match ($driver) {
            'frankenphp' => extension_loaded('frankenphp')
                || function_exists('frankenphp_loaded')
                || class_exists('Caddy'),
            'roadrunner' => class_exists('Spiral\RoadRunner\Worker')
                || function_exists('rr_version')
                || (PHP_SAPI === 'cli' && getenv('RR_MODE') !== false),
            'openswoole' => extension_loaded('openswoole')
                || extension_loaded('swoole')
                || class_exists('Swoole\Coroutine')
                || class_exists('OpenSwoole\Coroutine'),
            'sapi' => PHP_SAPI !== 'cli' || PHP_SAPI === 'phpdbg',
            default => false,
        };
    }

    private function writeReportToFile(RuntimeCapabilityVerificationReport $report, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $json = json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $written = @file_put_contents($path, $json);

        if ($written === false) {
            throw new RuntimeException(sprintf(
                'Failed to write check-suite report to [%s].',
                $path,
            ));
        }
    }
}
