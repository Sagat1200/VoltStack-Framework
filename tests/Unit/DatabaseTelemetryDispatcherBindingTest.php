<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\Engine\DirectoryDatabaseTelemetryAlertSamplingStore;
use Quantum\Database\Operation\Engine\HttpDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryAlertSamplingStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\NullDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\OpenTelemetryDatabaseTelemetryDispatcher;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseTelemetryDispatcherBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-observability-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_resolves_in_memory_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'in_memory');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_jsonl_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'jsonl');
        $app->make(ConfigRepository::class)->set('database.observability.jsonl_path', $this->basePath . DIRECTORY_SEPARATOR . 'database-events.jsonl');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(JsonLineDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_null_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'null');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(NullDatabaseTelemetryDispatcher::class, $dispatcher);
    }

    public function test_it_resolves_webhook_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'webhook');
        $app->make(ConfigRepository::class)->set('database.observability.webhook_url', 'https://monitoring.internal/voltstack/database');
        $app->make(ConfigRepository::class)->set('database.observability.webhook_timeout_ms', 5000);
        $app->make(ConfigRepository::class)->set('database.observability.webhook_headers', [
            'Authorization' => 'Bearer token',
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(HttpDatabaseTelemetryDispatcher::class, $dispatcher);
        self::assertSame('https://monitoring.internal/voltstack/database', $dispatcher->endpoint());
    }

    public function test_it_resolves_opentelemetry_dispatcher_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.dispatcher', 'opentelemetry');
        $app->make(ConfigRepository::class)->set('database.observability.opentelemetry.endpoint', 'https://collector.internal/v1/logs');
        $app->make(ConfigRepository::class)->set('database.observability.opentelemetry.service_name', 'voltstack-db');

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(OpenTelemetryDatabaseTelemetryDispatcher::class, $dispatcher);
        self::assertSame('https://collector.internal/v1/logs', $dispatcher->endpoint());
    }

    public function test_it_resolves_directory_alert_sampling_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.sqg_pipeline.alert_sampling_store', 'directory');
        $config->set(
            'database.observability.sqg_pipeline.alert_sampling_directory_path',
            $this->basePath . DIRECTORY_SEPARATOR . 'sampling-store',
        );

        $store = $app->make(DatabaseTelemetryAlertSamplingStoreInterface::class);

        self::assertInstanceOf(DirectoryDatabaseTelemetryAlertSamplingStore::class, $store);
        self::assertSame($this->basePath . DIRECTORY_SEPARATOR . 'sampling-store', $store->directoryPath());
    }

    public function test_it_resolves_in_memory_alert_sampling_store_when_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $app->make(ConfigRepository::class)->set('database.observability.sqg_pipeline.alert_sampling_store', 'in_memory');

        $store = $app->make(DatabaseTelemetryAlertSamplingStoreInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryAlertSamplingStore::class, $store);
    }

    public function test_it_uses_local_sqg_alert_profile_when_resolving_mapper_from_provider(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_profile', 'local');
        $config->set('database.observability.sqg_pipeline.alert_profiles.local', [
            'wide_search_candidate_count_max' => 6,
            'wide_search_candidate_count_avg' => 4.5,
            'no_gain_cost_delta_max' => -1.0,
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $lastSignal = $signals[array_key_last($signals)] ?? null;

        self::assertNotNull($lastSignal);
        self::assertSame([], $lastSignal->alerts);
    }

    public function test_it_allows_explicit_sqg_threshold_overrides_to_win_over_profile(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_profile', 'local');
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_max', 4);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_avg', 3.0);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.no_gain_cost_delta_max', 0.0);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $lastSignal = $signals[array_key_last($signals)] ?? null;

        self::assertNotNull($lastSignal);
        self::assertCount(3, $lastSignal->alerts);
        self::assertSame('database.sqg_pipeline.optimizer.wide_search', $lastSignal->alerts[0]['name']);
        self::assertSame(4, $lastSignal->alerts[0]['context']['threshold_candidate_count_max'] ?? null);
    }

    public function test_it_uses_sqg_alert_severity_profile_when_resolving_mapper_from_provider(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_max', 4);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_avg', 3.0);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.no_gain_cost_delta_max', 0.0);
        $config->set('database.observability.sqg_pipeline.alert_severity_profile', 'local');
        $config->set('database.observability.sqg_pipeline.alert_severity_profiles.local', [
            'database.sqg_pipeline.optimizer.wide_search' => 'info',
            'database.sqg_pipeline.optimizer.no_gain' => 'info',
            'database.sqg_pipeline.join_reorder.no_gain' => 'info',
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $lastSignal = $signals[array_key_last($signals)] ?? null;

        self::assertNotNull($lastSignal);
        self::assertCount(3, $lastSignal->alerts);
        self::assertSame('info', $lastSignal->alerts[0]['severity']);
        self::assertSame('info', $lastSignal->alerts[1]['severity']);
        self::assertSame('info', $lastSignal->alerts[2]['severity']);
    }

    public function test_it_allows_explicit_sqg_alert_severity_overrides_to_win_over_profile(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_max', 4);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.wide_search_candidate_count_avg', 3.0);
        $config->set('database.observability.sqg_pipeline.alert_thresholds.no_gain_cost_delta_max', 0.0);
        $config->set('database.observability.sqg_pipeline.alert_severity_profile', 'local');
        $config->set('database.observability.sqg_pipeline.alert_severity_profiles.local', [
            'database.sqg_pipeline.optimizer.wide_search' => 'info',
            'database.sqg_pipeline.optimizer.no_gain' => 'info',
            'database.sqg_pipeline.join_reorder.no_gain' => 'info',
        ]);
        $config->set('database.observability.sqg_pipeline.alert_severities', [
            'database.sqg_pipeline.optimizer.no_gain' => 'high',
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $lastSignal = $signals[array_key_last($signals)] ?? null;

        self::assertNotNull($lastSignal);
        self::assertCount(3, $lastSignal->alerts);
        self::assertSame('info', $lastSignal->alerts[0]['severity']);
        self::assertSame('high', $lastSignal->alerts[1]['severity']);
        self::assertSame('info', $lastSignal->alerts[2]['severity']);
    }

    public function test_it_uses_sqg_alert_sampling_profile_when_resolving_dispatcher_from_provider(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_thresholds', [
            'wide_search_candidate_count_max' => 4,
            'wide_search_candidate_count_avg' => 3.0,
            'no_gain_cost_delta_max' => 0.0,
        ]);
        $config->set('database.observability.sqg_pipeline.alert_sampling_profile', 'production');
        $config->set('database.observability.sqg_pipeline.alert_sampling_profiles.production', [
            'database.sqg_pipeline.optimizer.wide_search' => 3,
            'database.sqg_pipeline.optimizer.no_gain' => 3,
            'database.sqg_pipeline.join_reorder.no_gain' => 3,
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $secondSignal = $signals[1] ?? null;

        self::assertNotNull($secondSignal);
        self::assertSame([], $secondSignal->alerts);
        self::assertSame('production', $secondSignal->attributes['alert_sampling']['profile'] ?? null);
        self::assertSame('in_memory', $secondSignal->attributes['alert_sampling']['store'] ?? null);
        self::assertSame(3, $secondSignal->attributes['alert_sampling']['suppressed_total'] ?? null);
        self::assertSame(3, $secondSignal->attributes['alert_sampling']['cumulative_suppressed_total'] ?? null);
    }

    public function test_it_allows_explicit_sqg_alert_sampling_overrides_to_win_over_profile(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.observability.dispatcher', 'in_memory');
        $config->set('database.observability.sqg_pipeline.alert_thresholds', [
            'wide_search_candidate_count_max' => 4,
            'wide_search_candidate_count_avg' => 3.0,
            'no_gain_cost_delta_max' => 0.0,
        ]);
        $config->set('database.observability.sqg_pipeline.alert_sampling_profile', 'production');
        $config->set('database.observability.sqg_pipeline.alert_sampling_profiles.production', [
            'database.sqg_pipeline.optimizer.wide_search' => 5,
            'database.sqg_pipeline.optimizer.no_gain' => 5,
            'database.sqg_pipeline.join_reorder.no_gain' => 5,
        ]);
        $config->set('database.observability.sqg_pipeline.alert_sampling', [
            'database.sqg_pipeline.optimizer.wide_search' => 2,
            'database.sqg_pipeline.optimizer.no_gain' => 2,
            'database.sqg_pipeline.join_reorder.no_gain' => 2,
        ]);

        $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);

        self::assertInstanceOf(InMemoryDatabaseTelemetryDispatcher::class, $dispatcher);

        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $dispatcher->dispatch($this->sqgWideSearchNoGainReport());
        $signals = $dispatcher->signals();
        $secondSignal = $signals[1] ?? null;

        self::assertNotNull($secondSignal);
        self::assertCount(3, $secondSignal->alerts);
        self::assertSame(2, $secondSignal->alerts[0]['context']['sampling_every'] ?? null);
        self::assertSame(2, $secondSignal->alerts[0]['context']['sampling_occurrence'] ?? null);
        self::assertSame('production', $secondSignal->attributes['alert_sampling']['profile'] ?? null);
        self::assertSame(6, $secondSignal->attributes['alert_sampling']['cumulative_visible_total'] ?? null);
    }

    private function sqgWideSearchNoGainReport(): DatabaseTelemetryReport
    {
        return new DatabaseTelemetryReport(
            requestId: 'req-db-profile',
            tenantId: 'tenant-a',
            traceId: 'trace-db-profile',
            generatedAt: '2026-08-31T19:00:00+00:00',
            summary: [
                'total_operations' => 2,
                'completed' => 2,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'remote_replay_challenge' => [],
                'sqg_pipeline' => [
                    'observed_operations' => 2,
                    'join_reorder_selected' => 1,
                    'join_reorder_signatures' => ['u>p>a>o' => 1],
                    'estimated_cost_total' => 150.0,
                    'estimated_cost_avg' => 75.0,
                    'estimated_cost_min' => 70.0,
                    'estimated_cost_max' => 80.0,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 8,
                    'candidate_count_avg' => 4.0,
                    'candidate_count_max' => 4,
                    'optimizer_strategies' => ['safe_rule_bundle_v1' => 2],
                    'selected_candidates' => ['candidate:predicate_normalization_v1+join_reorder_v1' => 2],
                    'planner_logical_roots' => ['sort' => 2],
                    'planner_physical_roots' => ['sort_materialize' => 2],
                ],
                'latest' => [],
            ],
            health: [
                'total_segments' => 1,
                'closed_segments' => 1,
                'half_open_segments' => 0,
                'open_segments' => 0,
                'segments' => [],
            ],
            nodeId: 'node-a',
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
