<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Contracts\ConnectionInterface;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;
use Quantum\Database\Transaction\TransactionContext;
use Quantum\Database\Transaction\TransactionId;
use Quantum\Database\Transaction\TransactionManager;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;

final class DatabaseTransactionManagerOwnershipTest extends TestCase
{
    public function test_rollback_open_transactions_owned_skips_unowned_and_foreign_contexts(): void
    {
        $connections = new class implements ConnectionManagerInterface
        {
            public bool $connectionRequested = false;

            public function connection(?string $name = null): ConnectionInterface
            {
                $this->connectionRequested = true;

                throw new \RuntimeException('Connection access should not be required for skipped transactions.');
            }

            public function has(string $name): bool
            {
                return true;
            }

            public function defaultConnectionName(): string
            {
                return 'default';
            }

            public function disconnectAll(): void
            {
            }
        };

        $telemetry = new DatabaseTelemetryEmitter(
            new class implements TelemetryManagerInterface
            {
                public function emit(\Quantum\Telemetry\TelemetrySignal $signal): void
                {
                    throw new \RuntimeException('Telemetry should be disabled in this test.');
                }
            },
            new DatabaseConfiguration(telemetry: ['enabled' => false]),
        );

        $manager = new TransactionManager($connections, $telemetry);
        $reflection = new \ReflectionClass($manager);
        $contexts = $reflection->getProperty('contexts');
        $contexts->setAccessible(true);
        $contexts->setValue($manager, [
            'legacy' => new TransactionContext(new TransactionId('tx-legacy'), 'legacy', null, null),
            'foreign' => new TransactionContext(new TransactionId('tx-foreign'), 'foreign', 'scope-b', 'request-b'),
        ]);

        $report = $manager->rollbackOpenTransactionsOwned('scope-a');

        self::assertSame(0, $report['rolled_back']);
        self::assertSame(['legacy', 'foreign'], $report['skipped']);
        self::assertSame([], $report['errors']);
        self::assertFalse($connections->connectionRequested);
        self::assertSame(['legacy', 'foreign'], array_keys($contexts->getValue($manager)));
    }
}
