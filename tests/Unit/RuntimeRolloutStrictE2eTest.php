<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Console\Commands\RuntimeReleasePipelineCommand;
use Quantum\Console\Commands\RuntimeStatusCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Http\Contracts\Kernel as KernelContract;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Budget\RuntimeBudgetBaseline;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationReport;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrationStoreResolver;
use VoltStack\Runtime\Budget\RuntimeBudgetCalibrator;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidencePublisher;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationCheck;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;

/**
 * Validación end-to-end de la frontera operativa de rollout strict.
 *
 * El flow esperado para equipos de plataforma es:
 *   1. runner (FrankenPHP / RoadRunner / OpenSwoole real) produce un JSON
 *      compatible con RuntimeCapabilityVerificationReport.
 *   2. El equipo publica calibración runtime: budget empirico por driver.
 *   3. El equipo ingiere el report via runtime:evidence-ingest --activate.
 *   4. status --strict-rollout y pipeline deben pasar (ready=true).
 *
 * Aqui no inventamos ejecución nativa: reproducimos steps 2 y 3 con el
 * publisher real (no fixtures directos de stores) y validamos los
 * comandos CLI de status y pipeline.
 */
final class RuntimeRolloutStrictE2eTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-rollout-e2e-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'status.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);
        mkdir(dirname($this->telemetryPath), 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Rollout Strict E2E',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'telemetry.php',
            <<<PHP
<?php

declare(strict_types=1);

return [
    'exporter' => 'jsonl',
    'jsonl_path' => {$this->exportValue($this->telemetryPath)},
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
    'smoke_requests' => ['GET:/ok', 'GET:/health'],
];
PHP
        );

        $escapedBasePath = $this->exportValue($this->basePath);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use Quantum\Http\Contracts\Kernel as KernelContract;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

\$app->instance(\VoltStack\Framework\Contracts\Kernel::class, new class implements \VoltStack\Framework\Contracts\Kernel {
    public function handle(Request \$request): Response
    {
        return match (\$request->path()) {
            '/ok' => new Response('ok'),
            '/health' => new Response('healthy'),
            default => new Response('missing', 404),
        };
    }

    public function setMiddlewares(array \$middlewares): void {}

    public function pushMiddleware(callable|string|\Quantum\HttpKernel\Contracts\MiddlewareInterface \$middleware): void {}
});

\$app->boot();

return \$app;
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_strict_rollout_ready_when_published_calibration_and_native_verified_evidence_active(): void
    {
        // Paso 1: Publicar y activar una calibracion runtime empirica valida para frankenphp.
        $app = $this->bootstrapApp();
        $calibrationStore = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'frankenphp');
        $calibrationArtifact = $calibrationStore->publish(new RuntimeBudgetCalibrationReport(
            driver: 'frankenphp',
            profile: 'release',
            requestDefinitions: ['GET:/ok', 'GET:/health'],
            warmupIterations: 1,
            measuredIterations: 3,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('frankenphp', 80.0, 40.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('frankenphp', 120.0, 60.0, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 48.0, 'max_request_duration_ms' => 22.0, 'request_count' => 2],
                ['iteration' => 2, 'total_duration_ms' => 55.0, 'max_request_duration_ms' => 28.0, 'request_count' => 2],
                ['iteration' => 3, 'total_duration_ms' => 62.0, 'max_request_duration_ms' => 33.0, 'request_count' => 2],
            ],
            failures: [],
            totalStats: ['min' => 48.0, 'avg' => 55.0, 'p95' => 62.0, 'max' => 62.0],
            requestStats: ['min' => 22.0, 'avg' => 27.666, 'p95' => 33.0, 'max' => 33.0],
        ));
        $calibrationStore->activateGeneration($calibrationArtifact->generationId());

        // Paso 2: Construir un report de verificacion compatible con el catalogo,
        // usando IDs canonicos del catalogo (familia *.native). NO inventamos
        // ejecucion nativa: simplemente producimos un DTO valido alineado.
        $checks = $this->buildFullPassingChecksForFrankenPhp();
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-frankenphp-prod',
            profile: 'release',
            checks: $checks,
            metadata: [
                'php_version' => '8.4.3',
                'frankenphp_version' => '1.3.0',
                'producer' => 'frankenphp-native-check-suite',
            ],
        );

        // Sanity: report cumple lo que promueve a native-verified
        self::assertTrue($report->valid());
        self::assertTrue($report->provesNativeIntegration());
        self::assertSame('native-verified', $report->evidenceLevel());
        // y los audit checks required para frankenphp están todos presentes y passed
        $audit = RuntimeCapabilityCheckCatalog::auditRequiredChecks($report);
        self::assertSame([], $audit['missing_required']);
        self::assertSame([], $audit['failed_required']);

        // Paso 3: Publicar via EvidencePublisher (flow que usa runtime:evidence-ingest)
        // y activar la generation.
        $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
        $evidenceArtifact = $publisher->publish($report, app: $app);
        $evidenceStore = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'frankenphp');
        $evidenceStore->activateGeneration($evidenceArtifact->generationId());

        // Paso 4a: runtime:status --strict-rollout --json debe devolver ready=true (exit 0)
        $statusCmd = new RuntimeStatusCommand($this->basePath);
        $statusOut = new Output();
        $statusExit = $statusCmd->handle(Input::fromArgv([
            'volt',
            'runtime:status',
            '--driver=frankenphp',
            '--strict-rollout',
            '--json',
        ]), $statusOut);

        self::assertSame(0, $statusExit, 'runtime:status --strict-rollout debe retornar 0 en rollout listo.');
        $statusJson = json_decode(trim($statusOut->stdout()), true);
        self::assertIsArray($statusJson);
        self::assertSame('runtime:status', $statusJson['command'] ?? null);
        self::assertSame('native-verified', $statusJson['report']['capability_evidence']['level'] ?? null);
        self::assertSame(true, $statusJson['report']['capability_evidence']['native_integration_verified'] ?? null);
        self::assertSame(true, $statusJson['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame([], $statusJson['report']['rollout_readiness']['gaps'] ?? null);
        // Budget source debe ser published-calibration (adapter-default no vale para strict)
        self::assertSame('published-calibration', $statusJson['report']['recommended_budget']['source'] ?? null);
        self::assertSame('published-calibration', $statusJson['report']['rollout_readiness']['budget_source'] ?? null);
        // active evidence debe apuntar a la generation que acabamos de activar
        self::assertSame($evidenceArtifact->generationId(), $statusJson['report']['active_capability_evidence']['generation_id'] ?? null);
        self::assertSame('linux-frankenphp-prod', $statusJson['report']['active_capability_evidence']['platform'] ?? null);

        // Paso 4b: runtime:release-pipeline con requests reales debe pasar tambien.
        $pipelineCmd = new RuntimeReleasePipelineCommand($this->basePath);
        $pipelineOut = new Output();
        $pipelineExit = $pipelineCmd->handle(Input::fromArgv([
            'volt',
            'runtime:release-pipeline',
            '--driver=frankenphp',
            '--profile=release',
            '--requests=GET:/ok,GET:/health',
            // Budgets amplios para evitar flaky timing: el objetivo no es medir,
            // es confirmar que el rollout pasa cuando todo esta alineado.
            '--bootstrap-budget-total-ms=5000',
            '--runtime-budget-total-ms=2000',
            '--runtime-budget-request-ms=1000',
            '--warmup=1',
            '--iterations=2',
            '--no-rollback',
            '--emit-telemetry',
            '--json',
        ]), $pipelineOut);

        self::assertSame(0, $pipelineExit, 'runtime:release-pipeline debe retornar 0 con prerequisitos satisfechos.');
        $pipelineJson = json_decode(trim($pipelineOut->stdout()), true);
        self::assertIsArray($pipelineJson);
        self::assertSame('runtime:release-pipeline', $pipelineJson['command'] ?? null);
        self::assertSame(true, $pipelineJson['report']['passed'] ?? null);
        self::assertSame(null, $pipelineJson['report']['failed_stage'] ?? null);
        self::assertSame('frankenphp', $pipelineJson['report']['driver'] ?? null);
        self::assertSame('release', $pipelineJson['report']['profile'] ?? null);
        self::assertSame('native-verified', $pipelineJson['report']['capability_evidence']['level'] ?? null);
        self::assertSame(true, $pipelineJson['report']['capability_evidence']['native_integration_verified'] ?? null);
        self::assertSame($evidenceArtifact->generationId(), $pipelineJson['report']['active_capability_evidence']['generation_id'] ?? null);
        // Telemetry debe haber sido emitida
        self::assertSame(true, $pipelineJson['telemetry_emitted'] ?? null);
        $telemetryContent = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetryContent);
        self::assertStringContainsString('runtime_release_pipeline', $telemetryContent);
    }

    public function test_strict_rollout_fails_when_calibration_is_missing_even_if_native_evidence_is_active(): void
    {
        $app = $this->bootstrapApp();

        // Quito budgets de config/runtime.php para forzar "ninguna fuente de budget".
        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'driver' => 'frankenphp',
    'smoke_requests' => ['GET:/ok'],
];
PHP
        );

        // Publico y activo evidencia native-verified.
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-frankenphp-prod',
            profile: 'release',
            checks: $this->buildFullPassingChecksForFrankenPhp(),
        );
        $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
        $evidenceArtifact = $publisher->publish($report, app: $app);
        $evidenceStore = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'frankenphp');
        $evidenceStore->activateGeneration($evidenceArtifact->generationId());

        $statusCmd = new RuntimeStatusCommand($this->basePath);
        $statusOut = new Output();
        $statusExit = $statusCmd->handle(Input::fromArgv([
            'volt',
            'runtime:status',
            '--driver=frankenphp',
            '--strict-rollout',
            '--json',
        ]), $statusOut);

        self::assertSame(1, $statusExit, '--strict-rollout debe fallar cuando falta budget/calibracion valida.');
        $statusJson = json_decode(trim($statusOut->stdout()), true);
        self::assertIsArray($statusJson);
        self::assertSame(false, $statusJson['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame('native-verified', $statusJson['report']['capability_evidence']['level'] ?? null);
        // Gap debe ser que falta budget configurado o calibrado
        self::assertContains(
            'No existe budget runtime configurado o calibrado para un rollout persistente controlado.',
            $statusJson['report']['rollout_readiness']['gaps'] ?? [],
        );
    }

    public function test_strict_rollout_fails_when_native_evidence_is_not_active_even_if_calibration_exists(): void
    {
        $app = $this->bootstrapApp();

        // Publico y activo calibracion pero NO publico evidencia (queda contractual por adapter default)
        $calibrationStore = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'frankenphp');
        $calibrationArtifact = $calibrationStore->publish(new RuntimeBudgetCalibrationReport(
            driver: 'frankenphp',
            profile: 'release',
            requestDefinitions: ['GET:/ok'],
            warmupIterations: 1,
            measuredIterations: 2,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('frankenphp', 80.0, 40.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('frankenphp', 120.0, 60.0, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 40.0, 'max_request_duration_ms' => 20.0, 'request_count' => 1],
                ['iteration' => 2, 'total_duration_ms' => 45.0, 'max_request_duration_ms' => 22.0, 'request_count' => 1],
            ],
            failures: [],
            totalStats: ['min' => 40.0, 'avg' => 42.5, 'p95' => 45.0, 'max' => 45.0],
            requestStats: ['min' => 20.0, 'avg' => 21.0, 'p95' => 22.0, 'max' => 22.0],
        ));
        $calibrationStore->activateGeneration($calibrationArtifact->generationId());

        $statusCmd = new RuntimeStatusCommand($this->basePath);
        $statusOut = new Output();
        $statusExit = $statusCmd->handle(Input::fromArgv([
            'volt',
            'runtime:status',
            '--driver=frankenphp',
            '--strict-rollout',
            '--json',
        ]), $statusOut);

        self::assertSame(1, $statusExit);
        $statusJson = json_decode(trim($statusOut->stdout()), true);
        self::assertSame(false, $statusJson['report']['rollout_readiness']['ready'] ?? null);
        self::assertSame('contractual', $statusJson['report']['capability_evidence']['level'] ?? null);
        self::assertSame(false, $statusJson['report']['capability_evidence']['native_integration_verified'] ?? null);
        self::assertSame('published-calibration', $statusJson['report']['recommended_budget']['source'] ?? null);
        // Gap obligatorio: falta evidencia native-verified activa
        self::assertContains(
            'El runtime persistente aun no tiene evidencia nativa verificada en esta plataforma.',
            $statusJson['report']['rollout_readiness']['gaps'] ?? [],
        );
    }

    public function test_catalog_audit_notes_are_propagated_when_publisher_finds_unknown_or_missing_ids(): void
    {
        $app = $this->bootstrapApp();

        // Report con un check de familia nativa (promueve a native-verified)
        // pero con IDs desconocidos + le faltan varios required para frankenphp.
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-frankenphp-staging',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.boot', description: 'Pasa', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'made.up.unknown_id', description: 'Pasa', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'http.native.status200', description: 'Pasa', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'worker.drain.signal', description: 'Alias legacy', passed: true),
            ],
        );

        self::assertTrue($report->valid());
        self::assertTrue($report->provesNativeIntegration());

        $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
        $artifact = $publisher->publish($report, app: $app);

        $notes = $artifact->capabilities()->evidenceNotes();
        $notesStr = implode("\n", $notes);

        // Unknowns: made.up.unknown_id y worker.drain.signal (legacy alias antes del catalogo cerrado)
        self::assertStringContainsString('unknown check id(s) present (made.up.unknown_id, worker.drain.signal)', $notesStr);
        // Missing required para frankenphp: loop.native.start, loop.native.heartbeat, etc.
        self::assertStringContainsString('required native check id(s) missing for driver=frankenphp', $notesStr);
        self::assertStringContainsString('loop.native.start', $notesStr);
        self::assertStringContainsString('worker.native.drain', $notesStr);
    }

    /**
     * @return list<RuntimeCapabilityVerificationCheck> todos los required_for_native
     *   del catalogo para frankenphp, todos passing (no es ejecución real;
     *   es un DTO fixture alineado con el catalogo para validar el pipeline).
     */
    private function buildFullPassingChecksForFrankenPhp(): array
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp');
        $defs = RuntimeCapabilityCheckCatalog::definitions();

        $allRequiredPassed = [];
        foreach ($checks['required_for_native'] as $id) {
            $def = $defs[$id] ?? null;
            self::assertNotNull($def, sprintf('Catalog definition missing for required id=%s', $id));

            $allRequiredPassed[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: $def['description'],
                passed: true,
                durationMs: random_int(1, 20) / 1.0,
                notes: [sprintf('Plataforma frankenphp real valido: %s.', $id)],
                metadata: ['producer' => 'frankenphp-native-check-suite-e2e-fixture'],
            );
        }

        // Añado algunos recomendados passing para robustez (no obligatorio, pero
        // simula un report completo de plataforma).
        foreach (array_slice($checks['recommended'], 0, 3) as $id) {
            $def = $defs[$id] ?? null;
            if ($def === null) {
                continue;
            }
            $allRequiredPassed[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: $def['description'],
                passed: true,
                durationMs: random_int(1, 15) / 1.0,
                notes: [sprintf('Recomendado passing en frankenphp: %s.', $id)],
            );
        }

        return $allRequiredPassed;
    }

    /**
     * @return list<RuntimeCapabilityVerificationCheck> todos los required_for_native
     *   del catalogo para roadrunner/openswoole, todos passing.
     *   (estos NO incluyen http.native.* que solo required en frankenphp).
     */
    private function buildFullPassingChecksForRoadRunnerOrOpenSwoole(string $driver): array
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks($driver);

        $allRequiredPassed = [];
        foreach ($checks['required_for_native'] as $id) {
            $allRequiredPassed[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: sprintf('RoadRunner/OpenSwoole required id=%s passed (fixture).', $id),
                passed: true,
                durationMs: 0.5,
                notes: [],
                metadata: [],
            );
        }

        // Añadir ids recommended para que el report parezca "realista" (no required)
        // y capability flags concurrent/drain se promuevan.
        $recommendedIds = [
            'loop.native.jobs_queue',
            'worker.native.job_pool',
            'worker.native.job_dispatch',
            'signal.native.reload',
            'signal.native.heartbeat',
        ];
        foreach ($recommendedIds as $id) {
            $allRequiredPassed[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: sprintf('Recommended id=%s passed (fixture).', $id),
                passed: true,
                durationMs: 0.5,
                notes: [],
                metadata: [],
            );
        }

        return $allRequiredPassed;
    }

    public function test_e2e_strict_rollout_roadrunner_with_canonical_fixture_passes(): void
    {
        $app = $this->bootstrapApp();

        // 1) Publish calibration for roadrunner (paths por defecto compatibles con comandos CLI)
        $calibrationStore = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'roadrunner');
        $calibrationArtifact = $calibrationStore->publish(new RuntimeBudgetCalibrationReport(
            driver: 'roadrunner',
            profile: 'release',
            requestDefinitions: ['GET:/ok', 'GET:/health'],
            warmupIterations: 1,
            measuredIterations: 3,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('roadrunner', 80.0, 40.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('roadrunner', 120.0, 60.0, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 45.0, 'max_request_duration_ms' => 20.0, 'request_count' => 2],
                ['iteration' => 2, 'total_duration_ms' => 52.0, 'max_request_duration_ms' => 26.0, 'request_count' => 2],
                ['iteration' => 3, 'total_duration_ms' => 58.0, 'max_request_duration_ms' => 31.0, 'request_count' => 2],
            ],
            failures: [],
            totalStats: ['min' => 45.0, 'avg' => 51.666, 'p95' => 58.0, 'max' => 58.0],
            requestStats: ['min' => 20.0, 'avg' => 25.666, 'p95' => 31.0, 'max' => 31.0],
        ));
        $calibrationStore->activateGeneration($calibrationArtifact->generationId());

        // 2) Publish native-verified evidence (fixture) for roadrunner
        $checks = $this->buildFullPassingChecksForRoadRunnerOrOpenSwoole('roadrunner');
        // RoadRunner recommended extras: jobs / reload
        $rrExtras = [
            'loop.native.jobs_queue',
            'worker.native.job_pool',
            'worker.native.job_dispatch',
            'signal.native.reload',
            'adapter.native.spiral_match',
        ];
        foreach ($rrExtras as $id) {
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: 'Recomendado roadrunner fixture',
                passed: true,
                durationMs: 5.0,
                notes: [sprintf('Recomendado passing en roadrunner: %s.', $id)],
                metadata: ['fixture' => true, 'recommended' => true],
            );
        }
        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'rr-e2e-001',
            'generated_at' => date('c'),
            'driver' => 'roadrunner',
            'platform' => 'local-windows-roadrunner',
            'profile' => 'release',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'fixture-runbook', 'runner_version' => 'fixture-v1'],
        ]);
        self::assertTrue($report->valid());
        self::assertTrue($report->provesNativeIntegration());
        // Audit required debe estar limpio
        $audit = RuntimeCapabilityCheckCatalog::auditRequiredChecks($report);
        self::assertSame([], $audit['missing_required']);
        self::assertSame([], $audit['failed_required']);

        $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
        $evidenceArtifact = $publisher->publish(report: $report, app: $app);
        $evidenceStore = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'roadrunner');
        $evidenceStore->activateGeneration($evidenceArtifact->generationId());

        // 3) runtime:status --strict-rollout --json roadrunner
        $statusCmd = new RuntimeStatusCommand($this->basePath);
        $statusOut = new Output();
        $statusExit = $statusCmd->handle(Input::fromArgv([
            'volt',
            'runtime:status',
            '--driver=roadrunner',
            '--strict-rollout',
            '--json',
        ]), $statusOut);
        self::assertSame(0, $statusExit, sprintf('runtime:status roadrunner exit != 0. stdout=%s', $statusOut->stdout()));
        $statusArr = json_decode(trim($statusOut->stdout()), true);
        self::assertIsArray($statusArr);
        self::assertSame(true, $statusArr['report']['rollout_readiness']['ready'] ?? null, 'roadrunner rollout ready should be true.');
        self::assertSame([], $statusArr['report']['rollout_readiness']['gaps'] ?? null, 'roadrunner rollout gaps must be empty.');
        self::assertSame('published-calibration', $statusArr['report']['recommended_budget']['source'] ?? null);
        self::assertSame('published-calibration', $statusArr['report']['rollout_readiness']['budget_source'] ?? null);
        self::assertSame('native-verified', $statusArr['report']['capability_evidence']['level'] ?? null);
        self::assertSame($evidenceArtifact->generationId(), $statusArr['report']['active_capability_evidence']['generation_id'] ?? null);
        self::assertSame('local-windows-roadrunner', $statusArr['report']['active_capability_evidence']['platform'] ?? null);
        self::assertSame('native-verified', $statusArr['report']['active_capability_evidence']['capability_evidence']['level'] ?? null);

        // 4) runtime:release-pipeline roadrunner exit 0
        $pipelineCmd = new RuntimeReleasePipelineCommand($this->basePath);
        $pipelineOut = new Output();
        $pipelineExit = $pipelineCmd->handle(Input::fromArgv([
            'volt',
            'runtime:release-pipeline',
            '--driver=roadrunner',
            '--requests=GET:/ok',
            '--timeout=10',
            '--json',
        ]), $pipelineOut);
        self::assertSame(0, $pipelineExit, sprintf('runtime:release-pipeline roadrunner exit != 0. stdout=%s', $pipelineOut->stdout()));
        $pipelineArr = json_decode(trim($pipelineOut->stdout()), true);
        self::assertIsArray($pipelineArr);
        self::assertSame('runtime:release-pipeline', $pipelineArr['command'] ?? null);
        self::assertSame(true, $pipelineArr['report']['passed'] ?? null, 'roadrunner pipeline passed must be true.');
        self::assertSame('native-verified', $pipelineArr['report']['capability_evidence']['level'] ?? null);
        self::assertSame('roadrunner', $pipelineArr['report']['driver'] ?? null);
        self::assertSame(null, $pipelineArr['report']['failed_stage'] ?? null);
    }

    public function test_e2e_strict_rollout_openswoole_with_canonical_fixture_passes(): void
    {
        $app = $this->bootstrapApp();

        // 1) Publish calibration for openswoole (paths por defecto compatibles con comandos CLI)
        $calibrationStore = (new RuntimeBudgetCalibrationStoreResolver())->resolveForDriver($app, 'openswoole');
        $calibrationArtifact = $calibrationStore->publish(new RuntimeBudgetCalibrationReport(
            driver: 'openswoole',
            profile: 'release',
            requestDefinitions: ['GET:/ok', 'GET:/health'],
            warmupIterations: 1,
            measuredIterations: 3,
            safetyMultiplier: 1.25,
            currentBaseline: new RuntimeBudgetBaseline('openswoole', 80.0, 40.0, 'adapter-default'),
            recommendedBudget: new RuntimeBudgetBaseline('openswoole', 120.0, 60.0, 'empirical-calibration'),
            samples: [
                ['iteration' => 1, 'total_duration_ms' => 42.0, 'max_request_duration_ms' => 19.0, 'request_count' => 2],
                ['iteration' => 2, 'total_duration_ms' => 49.0, 'max_request_duration_ms' => 24.0, 'request_count' => 2],
                ['iteration' => 3, 'total_duration_ms' => 56.0, 'max_request_duration_ms' => 29.0, 'request_count' => 2],
            ],
            failures: [],
            totalStats: ['min' => 42.0, 'avg' => 49.0, 'p95' => 56.0, 'max' => 56.0],
            requestStats: ['min' => 19.0, 'avg' => 24.0, 'p95' => 29.0, 'max' => 29.0],
        ));
        $calibrationStore->activateGeneration($calibrationArtifact->generationId());

        // 2) Publish native-verified evidence (fixture) for openswoole
        $checks = $this->buildFullPassingChecksForRoadRunnerOrOpenSwoole('openswoole');
        // OpenSwoole coroutine/task/heartbeat recommended extras
        $osExtras = [
            'loop.native.coro_support',
            'signal.native.heartbeat',
            'coroutine.native.go',
            'coroutine.native.channel',
            'task.native.dispatch',
            'task.native.parallel',
            'adapter.native.openswoole_snapshot_match',
        ];
        foreach ($osExtras as $id) {
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: sprintf('OpenSwoole recommended id=%s passed (fixture).', $id),
                passed: true,
                durationMs: 0.5,
                notes: [sprintf('Recomendado passing en openswoole: %s.', $id)],
                metadata: ['fixture' => true, 'recommended' => true],
            );
        }
        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'os-e2e-001',
            'generated_at' => date('c'),
            'driver' => 'openswoole',
            'platform' => 'local-windows-openswoole',
            'profile' => 'release',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'fixture-runbook', 'runner_version' => 'fixture-v1'],
        ]);
        self::assertTrue($report->valid());
        self::assertTrue($report->provesNativeIntegration());
        // Audit required debe estar limpio
        $audit = RuntimeCapabilityCheckCatalog::auditRequiredChecks($report);
        self::assertSame([], $audit['missing_required']);
        self::assertSame([], $audit['failed_required']);

        $publisher = new RuntimeCapabilityEvidencePublisher($this->basePath);
        $evidenceArtifact = $publisher->publish(report: $report, app: $app);
        $evidenceStore = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'openswoole');
        $evidenceStore->activateGeneration($evidenceArtifact->generationId());

        // 3) runtime:status --strict-rollout --json openswoole
        $statusCmd = new RuntimeStatusCommand($this->basePath);
        $statusOut = new Output();
        $statusExit = $statusCmd->handle(Input::fromArgv([
            'volt',
            'runtime:status',
            '--driver=openswoole',
            '--strict-rollout',
            '--json',
        ]), $statusOut);
        self::assertSame(0, $statusExit, sprintf('runtime:status openswoole exit != 0. stdout=%s', $statusOut->stdout()));
        $statusArr = json_decode(trim($statusOut->stdout()), true);
        self::assertIsArray($statusArr);
        self::assertSame(true, $statusArr['report']['rollout_readiness']['ready'] ?? null, 'openswoole rollout ready should be true.');
        self::assertSame([], $statusArr['report']['rollout_readiness']['gaps'] ?? null, 'openswoole rollout gaps must be empty.');
        self::assertSame('published-calibration', $statusArr['report']['recommended_budget']['source'] ?? null);
        self::assertSame('published-calibration', $statusArr['report']['rollout_readiness']['budget_source'] ?? null);
        self::assertSame('native-verified', $statusArr['report']['capability_evidence']['level'] ?? null);
        self::assertSame($evidenceArtifact->generationId(), $statusArr['report']['active_capability_evidence']['generation_id'] ?? null);
        self::assertSame('local-windows-openswoole', $statusArr['report']['active_capability_evidence']['platform'] ?? null);
        self::assertSame('native-verified', $statusArr['report']['active_capability_evidence']['capability_evidence']['level'] ?? null);

        // 4) runtime:release-pipeline openswoole exit 0
        $pipelineCmd = new RuntimeReleasePipelineCommand($this->basePath);
        $pipelineOut = new Output();
        $pipelineExit = $pipelineCmd->handle(Input::fromArgv([
            'volt',
            'runtime:release-pipeline',
            '--driver=openswoole',
            '--requests=GET:/ok',
            '--timeout=10',
            '--json',
        ]), $pipelineOut);
        self::assertSame(0, $pipelineExit, sprintf('runtime:release-pipeline openswoole exit != 0. stdout=%s', $pipelineOut->stdout()));
        $pipelineArr = json_decode(trim($pipelineOut->stdout()), true);
        self::assertIsArray($pipelineArr);
        self::assertSame('runtime:release-pipeline', $pipelineArr['command'] ?? null);
        self::assertSame(true, $pipelineArr['report']['passed'] ?? null, 'openswoole pipeline passed must be true.');
        self::assertSame('native-verified', $pipelineArr['report']['capability_evidence']['level'] ?? null);
        self::assertSame('openswoole', $pipelineArr['report']['driver'] ?? null);
        self::assertSame(null, $pipelineArr['report']['failed_stage'] ?? null);
    }

    private function bootstrapApp(): Application
    {
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        self::assertInstanceOf(Application::class, $app);

        return $app;
    }

    private function exportValue(string $value): string
    {
        return var_export($value, true);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
