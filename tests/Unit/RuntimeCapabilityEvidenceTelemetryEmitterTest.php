<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationCheck;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;
use VoltStack\Runtime\Telemetry\RuntimeCapabilityEvidenceTelemetryEmitter;

final class RuntimeCapabilityEvidenceTelemetryEmitterTest extends TestCase
{
    public function test_emit_ingest_adds_source_and_empty_catalog_audit_when_report_is_clean(): void
    {
        $required = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp')['required_for_native'];
        $checks = [];
        foreach ($required as $id) {
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: 'ok',
                passed: true,
                durationMs: 1.0,
                notes: [],
                metadata: [],
            );
        }

        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'tel-clean-001',
            'generated_at' => date('c'),
            'driver' => 'frankenphp',
            'platform' => 'local-tel',
            'profile' => 'unit',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'frankenphp-real-check-suite'],
        ]);

        $captured = null;
        $manager = new class($this, $captured) implements TelemetryManagerInterface {
            public function __construct(private readonly TestCase $test, private mixed &$captured)
            {
            }

            public function emit(TelemetrySignal $signal): void
            {
                $this->captured = $signal;
            }
        };

        $emitter = new RuntimeCapabilityEvidenceTelemetryEmitter($manager);
        $emitter->emitIngest($report, true);

        self::assertInstanceOf(TelemetrySignal::class, $captured);
        self::assertSame('runtime_capability_evidence_ingest', $captured->name);
        self::assertSame('frankenphp-real-check-suite', $captured->attributes['source']);
        self::assertSame([], $captured->attributes['unknown_check_ids']);
        self::assertSame([], $captured->attributes['missing_required_check_ids']);
        self::assertSame([], $captured->attributes['failed_required_check_ids']);
    }

    public function test_emit_ingest_adds_unknown_missing_failed_and_source_for_noisy_report(): void
    {
        // Para frankenphp: emitimos worker.native.spawn = false (failed required),
        // quitamos http.native.status200 y http.native.headers (missing required),
        // añadimos made.up.unknown_id y coroutine.native.fictional como unknowns.
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
                description: 'ok',
                passed: $passed,
                durationMs: 1.0,
                notes: [],
                metadata: [],
            );
        }
        $checks[] = new RuntimeCapabilityVerificationCheck(
            id: 'made.up.unknown_id',
            description: 'unknown 1',
            passed: true,
            durationMs: 1.0,
            notes: [],
            metadata: [],
        );
        $checks[] = new RuntimeCapabilityVerificationCheck(
            id: 'coroutine.native.fictional',
            description: 'unknown 2',
            passed: true,
            durationMs: 1.0,
            notes: [],
            metadata: [],
        );

        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'tel-noisy-001',
            'generated_at' => date('c'),
            'driver' => 'frankenphp',
            'platform' => 'local-tel',
            'profile' => 'unit',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'sapi-local-test'],
        ]);

        $captured = null;
        $manager = new class($captured) implements TelemetryManagerInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function emit(TelemetrySignal $signal): void
            {
                $this->captured = $signal;
            }
        };

        $emitter = new RuntimeCapabilityEvidenceTelemetryEmitter($manager);
        $emitter->emitIngest($report, false);

        self::assertInstanceOf(TelemetrySignal::class, $captured);
        self::assertSame('sapi-local-test', $captured->attributes['source']);
        self::assertSame(false, $captured->attributes['published']);
        self::assertContains('made.up.unknown_id', $captured->attributes['unknown_check_ids']);
        self::assertContains('coroutine.native.fictional', $captured->attributes['unknown_check_ids']);
        self::assertContains('http.native.status200', $captured->attributes['missing_required_check_ids']);
        self::assertContains('http.native.headers', $captured->attributes['missing_required_check_ids']);
        self::assertSame(['worker.native.spawn'], $captured->attributes['failed_required_check_ids']);
    }
}
