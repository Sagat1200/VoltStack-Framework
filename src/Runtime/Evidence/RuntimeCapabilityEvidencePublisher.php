<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Evidence;

use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Runtime\RuntimeCapabilities;

/**
 * Traduce un RuntimeCapabilityVerificationReport (evidencia producida externamente
 * o desde el propio laboratorio) en un artefacto de evidencia publicable/activable
 * por driver/platform, reutilizando RuntimeCapabilityEvidenceStore.
 *
 * Regla de honestidad:
 *   - Si el reporte NO es válido (fallos checks) NO se publica como evidencia activa.
 *   - Se delega en el caller decidir si activa o no la generación publicada.
 */
final class RuntimeCapabilityEvidencePublisher
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param list<string> $extraNotes
     */
    public function publish(
        RuntimeCapabilityVerificationReport $report,
        ?Application $app = null,
        ?string $evidenceBaseDirectory = null,
        array $extraNotes = [],
    ): RuntimeCapabilityEvidenceArtifact {
        if (! $report->valid()) {
            throw new RuntimeException(sprintf(
                'Cannot publish runtime capability evidence for driver [%s] on platform [%s]: the verification report is not valid (%d/%d checks passed).',
                $report->driver(),
                $report->platform(),
                $report->passedChecks(),
                $report->totalChecks(),
            ));
        }

        $capabilities = $this->deriveCapabilities($report);

        $store = $this->resolveStore(
            app: $app,
            driver: $report->driver(),
            evidenceBaseDirectory: $evidenceBaseDirectory,
        );

        $notes = [...$report->evidenceNotes(), ...$this->catalogAuditNotes($report), ...$extraNotes];

        return $store->publish(
            driver: $report->driver(),
            platform: $report->platform(),
            capabilities: $capabilities,
            notes: $notes,
        );
    }

    private function deriveCapabilities(RuntimeCapabilityVerificationReport $report): RuntimeCapabilities
    {
        $passed = [];

        foreach ($report->checks() as $check) {
            if (! $check->passed()) {
                continue;
            }

            $passed[] = $check->id();
        }

        $persistent = $this->anyPassed($passed, [
            // canonical catalog ids
            'loop.native.boot',
            'loop.native.start',
            'loop.native.heartbeat',
            'loop.native.request_loop',
            'loop.native.low_latency',
            'worker.native.spawn',
            'worker.native.loop',
            'worker.native.persistent',
            'worker.native.concurrent_serve',
            'worker.native.graceful_drain',
            'http.native.error_recovery',
            // legacy aliases from prior cuts
            'worker.native.boot',
            'worker.native.lifecycle',
            'adapter.native.persistent',
        ]);

        $concurrent = $this->anyPassed($passed, [
            // canonical catalog ids
            'loop.native.parallel',
            'loop.native.task_steal',
            'loop.native.coro_support',
            'loop.native.jobs_queue',
            'worker.native.pool',
            'worker.native.concurrent_serve',
            'worker.native.job_pool',
            'worker.native.job_dispatch',
            'coroutine.native.go',
            'coroutine.native.channel',
            'task.native.dispatch',
            'task.native.parallel',
            // legacy aliases
            'concurrency.request.non_blocking',
            'concurrency.worker.parallel',
            'task.spawn.parallel',
            'adapter.native.concurrent',
        ]);

        $streaming = $this->anyPassed($passed, [
            // canonical catalog ids
            'http.native.stream',
            'http.native.ssechunk',
            'http.native.streaming_enabled',
            'worker.native.stream',
            'signal.native.stream_hook',
            // legacy aliases
            'http.native.streaming',
            'response.native.sse',
            'response.native.chunked',
        ]);

        $drainControl = $this->anyPassed($passed, [
            // canonical catalog ids
            'worker.native.drain',
            'worker.native.graceful_drain',
            'signal.native.stop',
            'signal.native.drain',
            'signal.native.sigterm',
            'signal.native.sigusr1',
            'signal.native.reload',
            'signal.native.heartbeat',
            // legacy aliases
            'worker.drain.signal',
            'worker.shutdown.graceful',
            'adapter.native.drain',
        ]);

        $nativeHttp = $this->anyPassed($passed, [
            // canonical catalog ids
            'http.native.status200',
            'http.native.headers',
            'http.native.status200_nofallback',
            'http.native.running_status',
            'http.native.requestcount',
            'http.native.response_time_under_100ms',
            'http.native.streaming_enabled',
            'http.native.body_readable',
            'http.native.headers_present',
            'http.native.routing_ok',
            'http.native.middleware_stack',
            'http.native.persistent_connection',
            'http.native.keepalive',
            // legacy aliases
            'http.native.request',
            'http.native.response',
            'adapter.native.http',
        ]);

        return new RuntimeCapabilities(
            persistent: $persistent,
            concurrent: $concurrent,
            streaming: $streaming,
            drainControl: $drainControl,
            nativeHttp: $nativeHttp,
            evidenceLevel: $report->evidenceLevel(),
            nativeIntegrationVerified: $report->provesNativeIntegration(),
            evidenceNotes: $report->evidenceNotes(),
        );
    }

    /**
     * @param list<string> $passedIds
     * @param list<string> $candidates
     */
    private function anyPassed(array $passedIds, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $passedIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveStore(
        ?Application $app,
        string $driver,
        ?string $evidenceBaseDirectory,
    ): RuntimeCapabilityEvidenceStore {
        if ($app === null) {
            $app = $this->bootstrapCurrentApplication();
        }

        return (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver(
            app: $app,
            driver: $driver,
            baseDirectory: $evidenceBaseDirectory,
        );
    }

    /**
     * @return list<string> notas de auditoría derivadas del catálogo cerrado.
     *   - IDs desconocidos (forward-compat: aceptados, advertencia en nota)
     *   - checks obligatorios faltantes para el driver (advertencia)
     *   - checks obligatorios fallados (ya debería haber sido bloqueado por report.valid())
     */
    private function catalogAuditNotes(RuntimeCapabilityVerificationReport $report): array
    {
        $notes = [];

        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);
        if ($audit['unknown_ids'] !== []) {
            $notes[] = sprintf(
                'RuntimeCapabilityCheckCatalog audit: %d unknown check id(s) present (%s); forward-compatible, no promotion implied.',
                count($audit['unknown_ids']),
                implode(', ', $audit['unknown_ids']),
            );
        }

        // Nota: solo aplicamos la auditoría de required-checks a drivers que
        // el catálogo conoce explícitamente. Drivers custom/forward-compat quedan sin nota.
        if (in_array($report->driver(), RuntimeCapabilityCheckCatalog::supportedPlatforms(), true)) {
            if ($audit['missing_required_ids'] !== []) {
                $notes[] = sprintf(
                    'RuntimeCapabilityCheckCatalog audit: %d required native check id(s) missing for driver=%s (%s).',
                    count($audit['missing_required_ids']),
                    $report->driver(),
                    implode(', ', $audit['missing_required_ids']),
                );
            }
            if ($audit['failed_required_ids'] !== []) {
                $notes[] = sprintf(
                    'RuntimeCapabilityCheckCatalog audit: %d required native check id(s) failed for driver=%s (%s).',
                    count($audit['failed_required_ids']),
                    $report->driver(),
                    implode(', ', $audit['failed_required_ids']),
                );
            }
        }

        return $notes;
    }

    private function bootstrapCurrentApplication(): Application
    {
        $bootstrapPath = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (! is_file($bootstrapPath)) {
            throw new RuntimeException(sprintf(
                'The application bootstrap file could not be found at [%s].',
                $bootstrapPath,
            ));
        }

        $app = require $bootstrapPath;

        if (! $app instanceof Application) {
            throw new RuntimeException('The application bootstrap file must return a VoltStack application instance.');
        }

        return $app;
    }
}
