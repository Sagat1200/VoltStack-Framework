<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AbuseProtection\BruteForceCounter;
use Quantum\Auth\AbuseProtection\DatabaseDistributedThrottleCounter;
use Quantum\Auth\AbuseProtection\ThrottleEngineV1;
use Quantum\Auth\AbuseProtection\CredentialStuffingBloomFilter;
use Quantum\Auth\AuthenticationServiceProvider;
use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;
use Quantum\Auth\Contracts\ThrottleDistributedStorageInterface;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Integration\DatabaseServiceProvider;
use VoltStack\Framework\Application;

final class BloqueI2DistributedThrottleDriverTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    public function test_i2_01_database_distributed_counter_persists_across_instances(): void
    {
        $databaseFile = $this->createTempDatabasePath();
        $appA = $this->makeApp($databaseFile, true);
        $appB = $this->makeApp($databaseFile, true);

        $counterA = $appA->make(DistributedThrottleCounterInterface::class);
        $counterB = $appB->make(DistributedThrottleCounterInterface::class);

        self::assertInstanceOf(DatabaseDistributedThrottleCounter::class, $counterA);
        self::assertInstanceOf(DatabaseDistributedThrottleCounter::class, $counterB);

        self::assertSame(1, $counterA->increment('identifier:alice|window:1m', 60, 100));
        self::assertSame(1, $counterB->currentCount('identifier:alice|window:1m', 100));
    }

    public function test_i2_02_database_distributed_counter_expires_events_by_window(): void
    {
        $databaseFile = $this->createTempDatabasePath();
        $app = $this->makeApp($databaseFile, true);
        $counter = $app->make(DistributedThrottleCounterInterface::class);

        self::assertInstanceOf(DatabaseDistributedThrottleCounter::class, $counter);

        $counter->increment('identifier:bob|window:1m', 60, 100);
        $counter->increment('identifier:bob|window:1m', 60, 130);

        self::assertSame(2, $counter->currentCount('identifier:bob|window:1m', 150));
        self::assertSame(1, $counter->currentCount('identifier:bob|window:1m', 161));
        self::assertSame(0, $counter->currentCount('identifier:bob|window:1m', 191));
    }

    public function test_i2_03_bruteforce_counter_uses_distributed_windows_exactly(): void
    {
        $databaseFile = $this->createTempDatabasePath();
        $app = $this->makeApp($databaseFile, true);
        $distributed = $app->make(DistributedThrottleCounterInterface::class);
        $counter = new BruteForceCounter($distributed);

        $counter->increment('identifier:carol', 100);
        $counter->increment('identifier:carol', 170);

        self::assertSame(
            ['1m' => 1, '5m' => 2, '15m' => 2],
            $counter->currentCounts('identifier:carol', 170),
        );

        $counter->reset('identifier:carol');

        self::assertSame(
            ['1m' => 0, '5m' => 0, '15m' => 0],
            $counter->currentCounts('identifier:carol', 170),
        );
    }

    public function test_i2_04_throttle_engine_shares_lockout_across_engines_with_same_database(): void
    {
        $databaseFile = $this->createTempDatabasePath();
        $appA = $this->makeApp($databaseFile, true);
        $appB = $this->makeApp($databaseFile, true);

        $engineA = new ThrottleEngineV1(
            bruteForceCounter: new BruteForceCounter($appA->make(DistributedThrottleCounterInterface::class)),
            bloomFilter: new CredentialStuffingBloomFilter(),
            thresholds: ['1m' => 3, '5m' => 5, '15m' => 8],
            retryAfterSeconds: 60,
        );
        $engineB = new ThrottleEngineV1(
            bruteForceCounter: new BruteForceCounter($appB->make(DistributedThrottleCounterInterface::class)),
            bloomFilter: new CredentialStuffingBloomFilter(),
            thresholds: ['1m' => 3, '5m' => 5, '15m' => 8],
            retryAfterSeconds: 60,
        );

        $engineA->recordAttempt('shared@example.com', false);
        $engineB->recordAttempt('shared@example.com', false);
        $engineA->recordAttempt('shared@example.com', false);

        $decision = $engineB->decide('shared@example.com');

        self::assertTrue($decision->isDenied());
        self::assertStringContainsString('brute_threshold_1m', (string) $decision->reasonCode);
    }

    public function test_i2_05_service_provider_aliases_storage_interface_to_same_distributed_counter(): void
    {
        $databaseFile = $this->createTempDatabasePath();
        $app = $this->makeApp($databaseFile, true);

        $counter = $app->make(DistributedThrottleCounterInterface::class);
        $storage = $app->make(ThrottleDistributedStorageInterface::class);

        self::assertInstanceOf(DatabaseDistributedThrottleCounter::class, $counter);
        self::assertSame($counter, $storage);
    }

    private function makeApp(string $databaseFile, bool $distributedEnabled): Application
    {
        $dir = dirname(__DIR__, 3);
        $app = new Application($dir);
        $app->instance(ConfigRepository::class, new ConfigRepository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'driver' => 'sqlite',
                        'database' => $databaseFile,
                    ],
                ],
            ],
            'auth' => [
                'session' => ['driver' => 'memory'],
                'tokens' => ['driver' => 'memory'],
                'throttle' => [
                    'enabled' => true,
                    'thresholds' => ['1m' => 3, '5m' => 5, '15m' => 8],
                    'distributed' => [
                        'enabled' => $distributedEnabled,
                        'driver' => 'database',
                        'database' => [
                            'connection' => 'default',
                            'table' => 'auth_throttle_events',
                        ],
                    ],
                ],
                'risk' => [
                    'enabled' => false,
                    'adaptive' => ['enabled' => false],
                ],
                'transaction' => ['nonce' => ['enabled' => false]],
                'assurance' => ['enabled' => false],
            ],
        ]));
        $app->register(DatabaseServiceProvider::class);
        $app->register(AuthenticationServiceProvider::class);

        return $app;
    }

    private function createTempDatabasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'volt-auth-throttle-');
        if ($path === false) {
            self::fail('Unable to allocate temporary sqlite path.');
        }

        $this->tempFiles[] = $path;

        return $path;
    }
}
