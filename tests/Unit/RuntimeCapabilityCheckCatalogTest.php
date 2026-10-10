<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use VoltStack\Runtime\Evidence\RuntimeCapabilityCheckCatalog;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationCheck;
use VoltStack\Runtime\Evidence\RuntimeCapabilityVerificationReport;

final class RuntimeCapabilityCheckCatalogTest extends TestCase
{
    public function test_native_families_are_five_closed_families(): void
    {
        $families = RuntimeCapabilityCheckCatalog::nativeFamilies();

        self::assertSame([
            'http.native',
            'loop.native',
            'worker.native',
            'signal.native',
            'adapter.native',
        ], $families);
    }

    public function test_all_known_ids_are_deterministic_and_non_empty(): void
    {
        $ids = RuntimeCapabilityCheckCatalog::allKnownCheckIds();

        self::assertNotEmpty($ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertGreaterThan(20, count($ids));
    }

    public function test_supported_platforms_contains_the_four_default_drivers(): void
    {
        $platforms = RuntimeCapabilityCheckCatalog::supportedPlatforms();

        self::assertContains('frankenphp', $platforms);
        self::assertContains('roadrunner', $platforms);
        self::assertContains('openswoole', $platforms);
        self::assertContains('sapi', $platforms);
    }

    public function test_unknown_check_ids_returns_ids_not_present_in_catalog(): void
    {
        $unknown = RuntimeCapabilityCheckCatalog::unknownCheckIds([
            'loop.native.boot',
            'http.native.status200',
            'made.up.check',
            'other.fictional.thing',
        ]);

        self::assertSame(['made.up.check', 'other.fictional.thing'], $unknown);
    }

    public function test_is_native_family_check_only_matches_closed_families_prefix(): void
    {
        self::assertTrue(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('loop.native.boot'));
        self::assertTrue(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('http.native.status200'));
        self::assertTrue(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('worker.native.spawn'));
        self::assertTrue(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('signal.native.sigterm'));
        self::assertTrue(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('adapter.native.registration'));
        self::assertFalse(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('bootstrap.app_booted'));
        self::assertFalse(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('config.snapshot_stable'));
        self::assertFalse(RuntimeCapabilityCheckCatalog::isNativeFamilyCheck('custom.native.fake'));
    }

    public function test_each_definition_has_required_shape_fields(): void
    {
        foreach (RuntimeCapabilityCheckCatalog::definitions() as $id => $def) {
            self::assertArrayHasKey('description', $def, sprintf('Missing description for id=%s', $id));
            self::assertArrayHasKey('family', $def, sprintf('Missing family for id=%s', $id));
            self::assertArrayHasKey('required_for_native_by_platform', $def, sprintf('Missing required_for_native_by_platform for id=%s', $id));
            self::assertArrayHasKey('recommended_for_platforms', $def, sprintf('Missing recommended_for_platforms for id=%s', $id));
            self::assertArrayHasKey('capability_flag', $def, sprintf('Missing capability_flag for id=%s', $id));
            self::assertIsString($def['description']);
            self::assertNotEmpty($def['description']);
            self::assertIsString($def['family']);
            self::assertIsArray($def['required_for_native_by_platform']);
            self::assertIsArray($def['recommended_for_platforms']);
            self::assertContains($def['capability_flag'], [null, 'persistent', 'concurrent', 'streaming', 'drain_control', 'native_http']);
        }
    }

    public function test_frankenphp_platform_checks_require_all_core_persistent_http_and_drain_ids(): void
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp');

        self::assertContains('loop.native.boot', $checks['required_for_native']);
        self::assertContains('loop.native.start', $checks['required_for_native']);
        self::assertContains('loop.native.heartbeat', $checks['required_for_native']);
        self::assertContains('http.native.status200', $checks['required_for_native']);
        self::assertContains('http.native.headers', $checks['required_for_native']);
        self::assertContains('http.native.status200_nofallback', $checks['required_for_native']);
        self::assertContains('http.native.running_status', $checks['required_for_native']);
        self::assertContains('http.native.requestcount', $checks['required_for_native']);
        self::assertContains('worker.native.spawn', $checks['required_for_native']);
        self::assertContains('worker.native.loop', $checks['required_for_native']);
        self::assertContains('worker.native.drain', $checks['required_for_native']);
        self::assertContains('signal.native.sigterm', $checks['required_for_native']);

        // recomendados pero no bloqueantes
        self::assertContains('http.native.stream', $checks['recommended']);
        self::assertContains('signal.native.sigusr1', $checks['recommended']);
    }

    public function test_roadrunner_requires_same_persistent_drain_but_not_nativehttp_yet(): void
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks('roadrunner');

        self::assertContains('loop.native.boot', $checks['required_for_native']);
        self::assertContains('worker.native.spawn', $checks['required_for_native']);
        self::assertContains('worker.native.drain', $checks['required_for_native']);
        self::assertContains('signal.native.sigterm', $checks['required_for_native']);
        // pero http.native no se REQUIERE todavía (adapter declara nativeHttp=false)
        self::assertNotContains('http.native.status200', $checks['required_for_native']);
        // sí aparece como recomendado para cuando el bridge HTTP exista
        self::assertContains('http.native.status200', $checks['recommended']);
        self::assertContains('loop.native.parallel', $checks['recommended']);
        self::assertContains('worker.native.pool', $checks['recommended']);
    }

    public function test_openswoole_marks_parallel_ids_as_recommended_only(): void
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks('openswoole');

        self::assertContains('loop.native.boot', $checks['required_for_native']);
        self::assertContains('worker.native.spawn', $checks['required_for_native']);
        self::assertContains('worker.native.drain', $checks['required_for_native']);
        self::assertContains('signal.native.sigterm', $checks['required_for_native']);
        self::assertContains('loop.native.parallel', $checks['recommended']);
        self::assertContains('loop.native.task_steal', $checks['recommended']);
        self::assertContains('http.native.stream', $checks['recommended']);
    }

    public function test_sapi_has_no_required_native_checks_and_only_auxiliary_recommendations(): void
    {
        $checks = RuntimeCapabilityCheckCatalog::platformChecks('sapi');

        self::assertSame([], $checks['required_for_native']);
        self::assertContains('bootstrap.app_booted', $checks['recommended']);
        self::assertContains('config.snapshot_stable', $checks['recommended']);
    }

    public function test_audit_required_checks_detects_missing_and_failed_ids_for_known_driver(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'frankenphp',
            platform: 'linux-frankenphp-prod',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.boot', description: 'ok', passed: true),
                new RuntimeCapabilityVerificationCheck(id: 'loop.native.start', description: 'ok', passed: false),
                // faltan: loop.native.heartbeat, worker.native.spawn, worker.native.loop,
                // worker.native.drain, signal.native.sigterm, http.native.status200,
                // http.native.headers, http.native.status200_nofallback, etc.
            ],
        );

        $audit = RuntimeCapabilityCheckCatalog::auditRequiredChecks($report);

        self::assertContains('loop.native.start', $audit['failed_required']);
        self::assertNotContains('loop.native.boot', $audit['missing_required']);
        self::assertContains('loop.native.heartbeat', $audit['missing_required']);
        self::assertContains('worker.native.spawn', $audit['missing_required']);
        self::assertContains('http.native.status200', $audit['missing_required']);
        self::assertContains('signal.native.sigterm', $audit['missing_required']);
    }

    public function test_audit_required_checks_returns_empty_when_report_uses_unknown_driver(): void
    {
        $report = new RuntimeCapabilityVerificationReport(
            driver: 'custom-platform',
            platform: 'whatever',
            profile: 'release',
            checks: [
                new RuntimeCapabilityVerificationCheck(id: 'boot.ok', description: 'ok', passed: true),
            ],
        );

        $audit = RuntimeCapabilityCheckCatalog::auditRequiredChecks($report);

        self::assertSame([], $audit['missing_required']);
        self::assertSame([], $audit['failed_required']);
    }

    public function test_definition_capability_flags_are_mapped_to_one_of_the_five_runtime_capabilities(): void
    {
        $flags = [];
        foreach (RuntimeCapabilityCheckCatalog::definitions() as $def) {
            if ($def['capability_flag'] !== null) {
                $flags[$def['capability_flag']] = true;
            }
        }

        $capabilityFlags = array_keys($flags);
        sort($capabilityFlags);

        self::assertSame(['concurrent', 'drain_control', 'native_http', 'persistent', 'streaming'], $capabilityFlags);
    }

    public function test_roadrunner_jobs_ids_definitions_exists_and_are_recommended_only(): void
    {
        $expectedIds = [
            'loop.native.jobs_queue',
            'worker.native.job_pool',
            'worker.native.job_dispatch',
            'signal.native.reload',
            'adapter.native.spiral_match',
        ];
        $defs = RuntimeCapabilityCheckCatalog::definitions();

        foreach ($expectedIds as $id) {
            self::assertArrayHasKey($id, $defs, sprintf('RoadRunner id=%s missing in catalog definitions', $id));

            $requiredByPlatform = $defs[$id]['required_for_native_by_platform'];
            self::assertFalse(
                $requiredByPlatform['roadrunner'] ?? false,
                sprintf('RoadRunner id=%s must not be required_for_native roadrunner (recommended-only until runner real emits it)', $id),
            );
            self::assertContains('roadrunner', $defs[$id]['recommended_for_platforms'], sprintf(
                'RoadRunner id=%s must be listed in recommended_for_platforms=[roadrunner]',
                $id,
            ));
        }
    }

    public function test_openswoole_coro_task_ids_definitions_exists_and_are_recommended_only(): void
    {
        $expectedIds = [
            'loop.native.coro_support',
            'coroutine.native.go',
            'coroutine.native.channel',
            'task.native.dispatch',
            'task.native.parallel',
            'signal.native.heartbeat',
            'adapter.native.openswoole_snapshot_match',
        ];
        $defs = RuntimeCapabilityCheckCatalog::definitions();

        foreach ($expectedIds as $id) {
            self::assertArrayHasKey($id, $defs, sprintf('OpenSwoole id=%s missing in catalog definitions', $id));

            $requiredByPlatform = $defs[$id]['required_for_native_by_platform'];
            self::assertFalse(
                $requiredByPlatform['openswoole'] ?? false,
                sprintf('OpenSwoole id=%s must not be required_for_native openswoole (recommended-only until runner real emits it)', $id),
            );
            self::assertContains('openswoole', $defs[$id]['recommended_for_platforms'], sprintf(
                'OpenSwoole id=%s must be listed in recommended_for_platforms=[openswoole]',
                $id,
            ));
        }
    }

    public function test_audit_report_returns_unknown_missing_failed_arrays(): void
    {
        // Report para frankenphp:
        // 1 unknown = made.up.unknown_id
        // le faltan 2 required (quitamos http.native.status200, http.native.headers)
        // y 1 required failed = worker.native.spawn passed=false
        $frankenRequired = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp')['required_for_native'];
        $skipMissing = ['http.native.status200', 'http.native.headers'];
        $checks = [];
        foreach ($frankenRequired as $id) {
            if (in_array($id, $skipMissing, true)) {
                continue;
            }
            $passed = $id === 'worker.native.spawn' ? false : true;
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: 'desc',
                passed: $passed,
                durationMs: 1.0,
                notes: [],
                metadata: [],
            );
        }
        $checks[] = new RuntimeCapabilityVerificationCheck(
            id: 'made.up.unknown_id',
            description: 'desc',
            passed: true,
            durationMs: 1.0,
            notes: [],
            metadata: [],
        );

        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'u-001',
            'generated_at' => date('c'),
            'driver' => 'frankenphp',
            'platform' => 'test',
            'profile' => 'unit',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'unit-test'],
        ]);

        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);

        self::assertSame(['made.up.unknown_id'], $audit['unknown_ids']);
        self::assertContains('http.native.status200', $audit['missing_required_ids']);
        self::assertContains('http.native.headers', $audit['missing_required_ids']);
        self::assertSame(['worker.native.spawn'], $audit['failed_required_ids']);
    }

    public function test_audit_report_succesful_frankenphp_3x_empty(): void
    {
        $frankenRequired = RuntimeCapabilityCheckCatalog::platformChecks('frankenphp')['required_for_native'];
        $checks = [];
        foreach ($frankenRequired as $id) {
            $checks[] = new RuntimeCapabilityVerificationCheck(
                id: $id,
                description: 'desc',
                passed: true,
                durationMs: 1.0,
                notes: [],
                metadata: [],
            );
        }

        $report = RuntimeCapabilityVerificationReport::fromArray([
            'report_uuid' => 'u-002',
            'generated_at' => date('c'),
            'driver' => 'frankenphp',
            'platform' => 'test',
            'profile' => 'unit',
            'checks' => array_map(
                static fn(RuntimeCapabilityVerificationCheck $c): array => $c->toArray(),
                $checks,
            ),
            'metadata' => ['source' => 'unit-test-ok'],
        ]);

        $audit = RuntimeCapabilityCheckCatalog::auditReport($report);

        self::assertSame([], $audit['unknown_ids']);
        self::assertSame([], $audit['missing_required_ids']);
        self::assertSame([], $audit['failed_required_ids']);
    }
}
