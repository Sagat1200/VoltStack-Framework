<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Transaction\Exception\TransactionAlreadyActiveException;
use Quantum\Database\Transaction\Exception\TransactionNotActiveException;
use Quantum\Database\Transaction\RetryPolicy;
use Quantum\Database\Transaction\TransactionDefinition;
use Quantum\Database\Transaction\TransactionIsolation;
use Quantum\Database\Transaction\TransactionPropagation;
use VoltStack\Framework\Application;

final class DatabaseTransactionAdvancedFeaturesTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-tx-advanced-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ConnectionManagerInterface::class)->disconnectAll();
            $this->app = null;
        }

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_begin_records_isolation_and_propagation_in_context(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        $definition = TransactionDefinition::defaults()
            ->withIsolation(TransactionIsolation::Serializable)
            ->withPropagation(TransactionPropagation::Required);

        $transactions->begin($definition);
        $context = $transactions->current();

        self::assertNotNull($context);
        self::assertSame(TransactionIsolation::Serializable, $context->definition()->isolation);
        self::assertSame(TransactionPropagation::Required, $context->definition()->propagation);

        $transactions->rollback();
    }

    public function test_propagation_mandatory_requires_active_transaction(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        $definition = TransactionDefinition::defaults()
            ->withPropagation(TransactionPropagation::Mandatory);

        $this->expectException(TransactionNotActiveException::class);

        $transactions->begin($definition);
    }

    public function test_propagation_never_rejects_active_transaction(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        $transactions->begin();

        $definition = TransactionDefinition::defaults()
            ->withPropagation(TransactionPropagation::Never);

        try {
            $transactions->begin($definition);
            self::fail('Expected TransactionAlreadyActiveException for Never propagation.');
        } catch (TransactionAlreadyActiveException $exception) {
            self::assertStringContainsString('active', strtolower($exception->getMessage()));
        } finally {
            $transactions->rollback();
        }
    }

    public function test_propagation_requires_new_is_not_supported(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        // Start an outer transaction first: REQUIRES_NEW is only problematic
        // when there is already an active transaction (it would need a second
        // physical connection, which is not supported in this iteration).
        $transactions->begin();

        $definition = TransactionDefinition::defaults()
            ->withPropagation(TransactionPropagation::RequiresNew);

        try {
            $transactions->begin($definition);
            self::fail('Expected PropagationNotSupportedException for RequiresNew.');
        } catch (\Quantum\Database\Transaction\Exception\PropagationNotSupportedException $exception) {
            self::assertStringContainsString('REQUIRES_NEW', $exception->getMessage());
        } finally {
            $transactions->rollback();
        }
    }

    public function test_required_propagation_with_active_creates_savepoint(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        $transactions->begin();
        self::assertSame(1, $transactions->current()?->depth());

        // A second begin() with Required (default) should nest via savepoint.
        $transactions->begin();
        self::assertSame(2, $transactions->current()?->depth());

        $transactions->rollback();
        self::assertSame(1, $transactions->current()?->depth());

        $transactions->rollback();
        self::assertFalse($transactions->isActive());
    }

    public function test_transaction_helper_commits_on_success(): void
    {
        $app = $this->makeApp();
        $this->createItemsTable($app);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        $result = $transactions->transaction(function () use ($db): string {
            $db->table('transaction_items')->insert(['name' => 'from-callback']);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['from-callback'], $this->names($app));
    }

    public function test_transaction_helper_rolls_back_on_exception(): void
    {
        $app = $this->makeApp();
        $this->createItemsTable($app);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        try {
            $transactions->transaction(function () use ($db): void {
                $db->table('transaction_items')->insert(['name' => 'will-rollback']);
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame([], $this->names($app));
        self::assertFalse($transactions->isActive());
    }

    public function test_retry_coordinator_retries_retryable_failure(): void
    {
        $app = $this->makeApp();
        $this->createItemsTable($app);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        $attempts = 0;
        $policy = new RetryPolicy(
            maxAttempts: 3,
            initialDelayMs: 0,
            maxDelayMs: 0,
            backoffMultiplier: 1.0,
            jitter: false,
        );

        $definition = TransactionDefinition::defaults()->withRetryPolicy($policy);

        $result = $transactions->transaction(function () use ($db, &$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                // Simulate a retryable rollback failure.
                throw new \Quantum\Database\Transaction\Exception\TransactionRollbackException('transient');
            }

            $db->table('transaction_items')->insert(['name' => 'after-retry']);

            return 'succeeded';
        }, null, $definition);

        self::assertSame('succeeded', $result);
        self::assertSame(2, $attempts);
        self::assertSame(['after-retry'], $this->names($app));
    }

    public function test_retry_coordinator_does_not_retry_non_retryable_failure(): void
    {
        $app = $this->makeApp();

        $transactions = $app->make(TransactionManagerInterface::class);

        $attempts = 0;
        $policy = new RetryPolicy(
            maxAttempts: 3,
            initialDelayMs: 0,
            maxDelayMs: 0,
            backoffMultiplier: 1.0,
            jitter: false,
        );

        $definition = TransactionDefinition::defaults()->withRetryPolicy($policy);

        try {
            $transactions->transaction(function () use (&$attempts): void {
                $attempts++;
                throw new \RuntimeException('not-retryable');
            }, null, $definition);
            self::fail('Expected RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('not-retryable', $exception->getMessage());
        }

        // A non-retryable failure should not trigger a retry.
        self::assertSame(1, $attempts);
    }

    public function test_before_commit_callback_runs_before_commit(): void
    {
        $app = $this->makeApp();
        $this->createItemsTable($app);

        $transactions = $app->make(TransactionManagerInterface::class);

        $beforeFired = false;
        $afterFired = false;

        $transactions->begin();
        $context = $transactions->current();
        $context->callbacks()->addBeforeCommit(function () use (&$beforeFired): void {
            $beforeFired = true;
        });
        $context->callbacks()->addAfterCommit(function () use (&$afterFired): void {
            $afterFired = true;
        });

        $transactions->commit();

        self::assertTrue($beforeFired);
        self::assertTrue($afterFired);
    }

    public function test_before_commit_failure_marks_rollback_only_and_rolls_back(): void
    {
        $app = $this->makeApp();
        $this->createItemsTable($app);

        $transactions = $app->make(TransactionManagerInterface::class);
        $db = $app->make(DatabaseQueryManager::class);

        $transactions->begin();
        $db->table('transaction_items')->insert(['name' => 'should-not-commit']);

        $context = $transactions->current();
        $context->callbacks()->addBeforeCommit(function (): void {
            throw new \RuntimeException('before-commit-failed');
        });

        try {
            $transactions->commit();
            self::fail('Expected commit to fail due to beforeCommit callback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('before-commit-failed', $exception->getMessage());
        }

        // The transaction should have been rolled back.
        self::assertFalse($transactions->isActive());
        self::assertSame([], $this->names($app));
    }

    public function test_after_rollback_callback_runs_on_rollback(): void
    {
        $app = $this->makeApp();

        $transactions = $app->make(TransactionManagerInterface::class);

        $afterRollbackFired = false;

        $transactions->begin();
        $context = $transactions->current();
        $context->callbacks()->addAfterRollback(function () use (&$afterRollbackFired): void {
            $afterRollbackFired = true;
        });

        $transactions->rollback();

        self::assertTrue($afterRollbackFired);
    }

    public function test_snapshot_contexts_includes_definition_metadata(): void
    {
        $app = $this->makeApp();
        $transactions = $app->make(TransactionManagerInterface::class);

        $definition = TransactionDefinition::defaults()
            ->withIsolation(TransactionIsolation::ReadCommitted)
            ->withPropagation(TransactionPropagation::Required)
            ->withReadOnly(true);

        $transactions->begin($definition);

        $snapshots = $transactions->snapshotContexts();

        self::assertCount(1, $snapshots);
        self::assertSame('READ COMMITTED', $snapshots[0]['isolation']);
        self::assertSame('required', $snapshots[0]['propagation']);
        self::assertTrue($snapshots[0]['read_only']);

        $transactions->rollback();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('database.default', 'default');
        $app->make(ConfigRepository::class)->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $this->app = $app;
    }

    private function createItemsTable(Application $app): void
    {
        $app->make(SchemaManager::class)->create('transaction_items', function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('name');
        }, true);
    }

    /**
     * @return list<string>
     */
    private function names(Application $app): array
    {
        $rows = $app->make(DatabaseQueryManager::class)
            ->table('transaction_items')
            ->select('name')
            ->orderBy('name')
            ->get()
            ->rows();

        return array_values(array_map(static fn(array $row): string => (string) $row['name'], $rows));
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
