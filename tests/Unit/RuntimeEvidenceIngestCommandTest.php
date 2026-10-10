<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Commands\RuntimeEvidenceIngestCommand;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStoreResolver;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationCheck;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;

final class RuntimeEvidenceIngestCommandTest extends TestCase
{
    private string $basePath;

    private string $telemetryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-evidence-ingest-' . uniqid('', true);
        $this->telemetryPath = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'telemetry' . DIRECTORY_SEPARATOR . 'runtime-evidence.jsonl';

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Runtime Evidence Ingest',
    'env' => 'testing',
    'providers' => [],
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

        $escapedBasePath = $this->exportValue($this->basePath);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

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

    public function test_it_ingests_a_valid_native_report_and_publishes_evidence(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.boot', description: 'Boot nativo OK', passed: true, durationMs: 10.0),
                new RuntimeCapabilityVerificationCheck(id: 'http.native.status200', description: 'HTTP OK', passed: true, durationMs: 1.0),
            ],
        );

        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--json-string=' . (string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES),
                '--activate',
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame('runtime:evidence-ingest', $decoded['command'] ?? null);
        self::assertSame(true, $decoded['published'] ?? null);
        self::assertSame(true, $decoded['activated'] ?? null);
        self::assertSame('frankenphp', $decoded['published_artifact']['driver'] ?? null);
        self::assertSame('windows-frankenphp-dev', $decoded['published_artifact']['platform'] ?? null);
        self::assertSame('native-verified', $decoded['report']['evidence_level'] ?? null);

        $app = new Application($this->basePath);
        $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'frankenphp');
        $current = $store->currentArtifact();

        self::assertNotNull($current);
        self::assertSame($decoded['published_artifact']['generation_id'] ?? null, $current->generationId());
        self::assertSame('native-verified', $current->capabilities()->evidenceLevel());
    }

    public function test_it_ingests_from_input_file_without_activate_flag(): void
    {
        $inputFile = $this->basePath . DIRECTORY_SEPARATOR . 'evidence-report.json';
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-build',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck('boot.config.loaded', 'Config OK', true),
                new RuntimeCapabilityVerificationCheck('boot.providers.registered', 'Providers OK', true),
            ],
        );

        file_put_contents($inputFile, (string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES));

        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--input=' . $inputFile,
                '--json',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(true, $decoded['published'] ?? null);
        self::assertSame(false, $decoded['activated'] ?? null);
        self::assertSame('contractual', $decoded['report']['evidence_level'] ?? null);

        $app = new Application($this->basePath);
        $store = (new RuntimeCapabilityEvidenceStoreResolver())->resolveForDriver($app, 'frankenphp');
        $current = $store->currentArtifact();

        // Sin --activate no hay generation current activa
        self::assertNull($current);
    }

    public function test_it_fails_when_payload_has_check_failures(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.boot', description: 'Boot nativo OK', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'http.native.status200', description: 'HTTP falló', passed: false, durationMs: 1.0, notes: ['Timeout 10s']),
            ],
        );

        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--json-string=' . (string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES),
                '--json',
            ]),
            $output,
        );

        self::assertSame(1, $exitCode);
        $decoded = json_decode(trim($output->stdout()), true);
        self::assertIsArray($decoded);
        self::assertSame(false, $decoded['published'] ?? null);
        self::assertNotNull($decoded['publish_error'] ?? null);
        self::assertStringContainsString('not valid', (string) ($decoded['publish_error'] ?? ''));
    }

    public function test_it_fails_when_no_payload_is_provided(): void
    {
        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--input=/path/to/report.json or --json-string="..."');

        $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--json',
            ]),
            $output,
        );
    }

    public function test_it_emits_telemetry_when_flag_is_passed(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck('loop.native.boot', 'Boot nativo OK', true),
                new RuntimeCapabilityVerificationCheck('http.native.status200', 'HTTP OK', true),
            ],
        );

        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--json-string=' . (string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES),
                '--activate',
                '--emit-telemetry',
            ]),
            $output,
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Runtime capability evidence ingest:', $output->stdout());
        self::assertStringContainsString('Telemetry: emitted', $output->stdout());

        $telemetry = file_get_contents($this->telemetryPath);
        self::assertIsString($telemetry);
        self::assertStringContainsString('"type":"runtime_capability_evidence_ingest"', $telemetry);
        self::assertStringContainsString('"proves_native_integration":true', $telemetry);
        self::assertStringContainsString('"driver":"frankenphp"', $telemetry);
        self::assertStringContainsString('"platform":"windows-frankenphp-dev"', $telemetry);
    }

    public function test_evidence_ingest_command_json_output_includes_catalog_audit_and_source(): void
    {
        // Noise frankenphp report:
        //   source = fixture-test
        //   failed required: worker.native.spawn passed=false
        //   missing required: quitamos http.native.status200 y http.native.headers
        //   unknowns: made.up.unknown_id + coroutine.native.fictional
        $required = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp')['required_for_native'];
        $skipMissing = ['http.native.status200', 'http.native.headers'];
        $checks = [];
        foreach ($required as $id) {
            if (in_array($id, $skipMissing, true)) {
                continue;
            }
            $passed = $id !== 'worker.native.spawn';
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: 'check',
                passed: $passed,
                durationMs: 1.0,
                notes: [],
                metadata: [],
            );
        }
        $checks[] = new RuntimeCapabilityVerificationCheck(
            id: 'made.up.unknown_id',
            description: 'unknown1',
            passed: true,
            durationMs: 1.0,
            notes: [],
            metadata: [],
        );
        $checks[] = new RuntimeCapabilityVerificationCheck(
            id: 'coroutine.native.fictional',
            description: 'unknown2',
            passed: true,
            durationMs: 1.0,
            notes: [],
            metadata: [],
        );

        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'json-audit-001',
            'generated_at' => date('c'),
            'driver' => 'frankenphp',
            'platform' => 'windows-test',
            'profile' => 'release',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'fixture-test'],
        ]);

        $command = new RuntimeEvidenceIngestCommand($this->basePath);
        $output = new Output();

        $exitCode = $command->handle(
            Input::fromArgv([
                'volt',
                'runtime:evidence-ingest',
                '--json-string=' . (string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES),
                '--json',
            ]),
            $output,
        );

        // report NO valid (worker.native.spawn failed required) => exit 1 expected.
        self::assertSame(1, $exitCode, sprintf('Exit expected 1 by check failures. stdout: %s', $output->stdout()));

        $decoded = json_decode($output->stdout(), true);
        self::assertIsArray($decoded);
        self::assertSame('fixture-test', $decoded['source'] ?? null);
        self::assertIsArray($decoded['catalog_audit'] ?? null);
        $audit = $decoded['catalog_audit'];
        self::assertContains('made.up.unknown_id', $audit['unknown_check_ids'] ?? []);
        self::assertContains('coroutine.native.fictional', $audit['unknown_check_ids'] ?? []);
        self::assertContains('http.native.status200', $audit['missing_required_check_ids'] ?? []);
        self::assertContains('http.native.headers', $audit['missing_required_check_ids'] ?? []);
        self::assertSame(['worker.native.spawn'], $audit['failed_required_check_ids'] ?? null);
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
