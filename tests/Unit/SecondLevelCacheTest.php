<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Cache\SecondLevelCache;

/**
 * DV-DB-041: Second-Level Cache V2 unit tests.
 *
 * Covers the in-memory SecondLevelCache behaviour that is hard to exercise
 * end-to-end: TTL expiry, collection-level invalidation, query-result
 * eviction tied to row writes, and per-entity-class key namespacing.
 */
final class SecondLevelCacheTest extends TestCase
{
    public function test_remember_and_recall_row_round_trips_full_row(): void
    {
        $cache = new SecondLevelCache();

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);

        self::assertSame(['id' => 1, 'name' => 'Ada'], $cache->recallRow('App\User', '1'));
        self::assertTrue($cache->hasRow('App\User', '1'));
        self::assertFalse($cache->hasRow('App\User', '2'));
    }

    public function test_row_entries_are_namespaced_by_entity_class(): void
    {
        // Two different entity classes sharing the same identifier must NOT collide.
        $cache = new SecondLevelCache();

        $cache->rememberRow('App\Author', '1', ['id' => 1, 'name' => 'Ada']);
        $cache->rememberRow('App\Publisher', '1', ['id' => 1, 'name' => 'VoltStack Press']);

        self::assertSame('Ada', $cache->recallRow('App\Author', '1')['name']);
        self::assertSame('VoltStack Press', $cache->recallRow('App\Publisher', '1')['name']);
    }

    public function test_row_ttl_expiry_returns_null_and_evicts_entry(): void
    {
        $cache = new SecondLevelCache(defaultTtlSeconds: 0);

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);

        // A TTL of 0 means "expired immediately" (expiresAt = now + 0,
        // and isExpired uses microtime(true) > expiresAt, so any time spent between
        // remember and recall makes it expire).
        usleep(1000);

        self::assertNull($cache->recallRow('App\User', '1'), 'Expired row must be evicted on recall.');
        self::assertFalse($cache->hasRow('App\User', '1'));
        self::assertSame(0, $cache->rowCount());
    }

    public function test_per_entry_ttl_overrides_default(): void
    {
        $cache = new SecondLevelCache(defaultTtlSeconds: 3600);

        // Per-entry TTL of 0 expires immediately, overriding the long default.
        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada'], ttlSeconds: 0);
        usleep(1000);

        self::assertNull($cache->recallRow('App\User', '1'));
    }

    public function test_null_ttl_means_infinite_lifetime(): void
    {
        $cache = new SecondLevelCache(defaultTtlSeconds: null);

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);
        usleep(1000);

        self::assertNotNull($cache->recallRow('App\User', '1'));
    }

    public function test_evict_row_removes_row_and_all_query_results_for_class(): void
    {
        $cache = new SecondLevelCache();

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);
        $cache->rememberQueryResult('App\User', 'ids::1', [['id' => 1, 'name' => 'Ada']]);
        $cache->rememberQueryResult('App\User', 'all', [['id' => 1, 'name' => 'Ada']]);
        $cache->rememberRow('App\Post', '1', ['id' => 1, 'title' => 'Hi']);
        $cache->rememberQueryResult('App\Post', 'all', [['id' => 1, 'title' => 'Hi']]);

        $cache->evictRow('App\User', '1');

        self::assertNull($cache->recallRow('App\User', '1'));
        self::assertNull($cache->recallQueryResult('App\User', 'ids::1'));
        self::assertNull($cache->recallQueryResult('App\User', 'all'));

        // Other classes are untouched.
        self::assertNotNull($cache->recallRow('App\Post', '1'));
        self::assertNotNull($cache->recallQueryResult('App\Post', 'all'));
    }

    public function test_evict_all_clears_rows_and_queries_for_one_class_only(): void
    {
        $cache = new SecondLevelCache();

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);
        $cache->rememberRow('App\User', '2', ['id' => 2, 'name' => 'Linus']);
        $cache->rememberQueryResult('App\User', 'all', [['id' => 1], ['id' => 2]]);
        $cache->rememberRow('App\Post', '1', ['id' => 1, 'title' => 'Hi']);
        $cache->rememberQueryResult('App\Post', 'all', [['id' => 1]]);

        $cache->evictAll('App\User');

        // All User rows and query results are gone.
        self::assertNull($cache->recallRow('App\User', '1'));
        self::assertNull($cache->recallRow('App\User', '2'));
        self::assertNull($cache->recallQueryResult('App\User', 'all'));

        // Only the Post row remains (rowCount reflects all classes).
        self::assertSame(1, $cache->rowCount(), 'evictAll must remove only the target class rows.');
        self::assertSame(1, $cache->queryCount());

        // Other classes survive.
        self::assertNotNull($cache->recallRow('App\Post', '1'));
        self::assertNotNull($cache->recallQueryResult('App\Post', 'all'));
    }

    public function test_query_results_are_namespaced_by_entity_class(): void
    {
        // Two classes using the same query key (e.g. 'ids::1') must NOT collide.
        $cache = new SecondLevelCache();

        $cache->rememberQueryResult('App\Author', 'ids::1', [['id' => 1, 'name' => 'Ada']]);
        $cache->rememberQueryResult('App\Publisher', 'ids::1', [['id' => 1, 'name' => 'VoltStack Press']]);

        self::assertSame('Ada', $cache->recallQueryResult('App\Author', 'ids::1')[0]['name']);
        self::assertSame('VoltStack Press', $cache->recallQueryResult('App\Publisher', 'ids::1')[0]['name']);
    }

    public function test_query_result_ttl_expiry(): void
    {
        $cache = new SecondLevelCache(defaultTtlSeconds: 0);

        $cache->rememberQueryResult('App\User', 'all', [['id' => 1, 'name' => 'Ada']]);
        usleep(1000);

        self::assertNull($cache->recallQueryResult('App\User', 'all'));
        self::assertSame(0, $cache->queryCount());
    }

    public function test_shared_instance_is_visible_to_multiple_callers(): void
    {
        // Two callers sharing the same SecondLevelCache instance must see each
        // other's entries (simulates two EntityManagers in the same scope).
        $cache = new SecondLevelCache();

        $cache->rememberRow('App\User', '1', ['id' => 1, 'name' => 'Ada']);
        $cache->rememberQueryResult('App\User', 'ids::1', [['id' => 1, 'name' => 'Ada']]);

        // A second caller referencing the same instance sees the data.
        $secondReference = $cache;
        self::assertNotNull($secondReference->recallRow('App\User', '1'));
        self::assertNotNull($secondReference->recallQueryResult('App\User', 'ids::1'));
    }

    public function test_row_and_query_counters_reflect_stored_entries(): void
    {
        $cache = new SecondLevelCache();

        self::assertSame(0, $cache->rowCount());
        self::assertSame(0, $cache->queryCount());

        $cache->rememberRow('App\User', '1', ['id' => 1]);
        $cache->rememberQueryResult('App\User', 'all', [['id' => 1]]);

        self::assertSame(1, $cache->rowCount());
        self::assertSame(1, $cache->queryCount());

        $cache->evictAll('App\User');

        self::assertSame(0, $cache->rowCount());
        self::assertSame(0, $cache->queryCount());
    }
}
