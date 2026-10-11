<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Cache;

/**
 * Second-Level Cache (SLC) V2 for the ORM.
 *
 * Acts as an L2 cache layer above the identity map (L1). Stores:
 *   - hydration rows (full DB rows keyed by entityClass::identifier)
 *   - query result sets (keyed by a deterministic query signature)
 *
 * Both stores support optional TTL (time-to-live). When TTL is null, entries
 * live for the entire scope lifetime. The cache is designed to be bound as a
 * SCOPED service so that multiple EntityManagers within the same request
 * scope share the same instance.
 *
 * Invalidation model:
 *   - evictRow(class, id): removes a single row AND all query results for
 *     that class (a row change may affect any cached query that returned it).
 *   - evictAll(class): removes all rows AND query results for that class.
 *   - rememberRow/rememberQueryResult: insert or overwrite.
 *   - recall*: return null if absent or expired (expired entries are evicted).
 */
final class SecondLevelCache
{
    /**
     * @var array<string, array{row: array<string, mixed>, expiresAt: float|null}>
     */
    private array $rows = [];

    /**
     * Index: entityClass => [rowKey => true]
     *
     * @var array<class-string, array<string, true>>
     */
    private array $rowClassIndex = [];

    /**
     * @var array<string, array{rows: list<array<string, mixed>>, expiresAt: float|null}>
     */
    private array $queries = [];

    /**
     * Index: entityClass => [queryKey => true]
     *
     * @var array<class-string, array<string, true>>
     */
    private array $queryClassIndex = [];

    /**
     * @param int|null $defaultTtlSeconds Default TTL in seconds for entries
     *                                    that don't specify their own. null =
     *                                    infinite (lives for the whole scope).
     */
    public function __construct(
        private readonly ?int $defaultTtlSeconds = null,
    ) {
    }

    public function defaultTtlSeconds(): ?int
    {
        return $this->defaultTtlSeconds;
    }

    /**
     * Store a full hydration row for later cross-call-site reuse.
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $row
     * @param int|null $ttlSeconds Override TTL for this entry.
     */
    public function rememberRow(string $entityClass, int|string $identifier, array $row, ?int $ttlSeconds = null): void
    {
        $key = $this->rowKey($entityClass, $identifier);
        $this->rows[$key] = [
            'row' => $row,
            'expiresAt' => $this->expiresAt($ttlSeconds),
        ];
        $this->rowClassIndex[$entityClass][$key] = true;
    }

    /**
     * Recall a cached hydration row, or null when absent / expired.
     *
     * Expired entries are evicted as a side effect.
     *
     * @param class-string $entityClass
     * @return array<string, mixed>|null
     */
    public function recallRow(string $entityClass, int|string $identifier): ?array
    {
        $key = $this->rowKey($entityClass, $identifier);
        $entry = $this->rows[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($this->isExpired($entry['expiresAt'])) {
            unset($this->rows[$key], $this->rowClassIndex[$entityClass][$key]);

            return null;
        }

        return $entry['row'];
    }

    /**
     * Whether a non-expired row exists for the given identifier.
     *
     * @param class-string $entityClass
     */
    public function hasRow(string $entityClass, int|string $identifier): bool
    {
        return $this->recallRow($entityClass, $identifier) !== null;
    }

    /**
     * Remove a single row entry AND invalidate all cached query results for
     * the same entity class (a row change may have affected any query that
     * previously returned it).
     *
     * @param class-string $entityClass
     */
    public function evictRow(string $entityClass, int|string $identifier): void
    {
        $key = $this->rowKey($entityClass, $identifier);
        unset($this->rows[$key], $this->rowClassIndex[$entityClass][$key]);
        $this->evictQueryResults($entityClass);
    }

    /**
     * Remove ALL rows and query results for a given entity class.
     *
     * Use this after bulk writes (mass update / delete) that bypass the
     * per-row eviction path.
     *
     * @param class-string $entityClass
     */
    public function evictAll(string $entityClass): void
    {
        foreach (array_keys($this->rowClassIndex[$entityClass] ?? []) as $key) {
            unset($this->rows[$key]);
        }
        unset($this->rowClassIndex[$entityClass]);

        $this->evictQueryResults($entityClass);
    }

    /**
     * Store a query result set (list of full rows) keyed by a deterministic
     * query signature.
     *
     * @param class-string $entityClass
     * @param list<array<string, mixed>> $rows
     * @param int|null $ttlSeconds Override TTL for this entry.
     */
    public function rememberQueryResult(string $entityClass, string $queryKey, array $rows, ?int $ttlSeconds = null): void
    {
        $fullKey = $this->queryKey($entityClass, $queryKey);
        $this->queries[$fullKey] = [
            'rows' => $rows,
            'expiresAt' => $this->expiresAt($ttlSeconds),
        ];
        $this->queryClassIndex[$entityClass][$fullKey] = true;
    }

    /**
     * Recall a cached query result set, or null when absent / expired.
     *
     * @param class-string $entityClass
     * @return list<array<string, mixed>>|null
     */
    public function recallQueryResult(string $entityClass, string $queryKey): ?array
    {
        $fullKey = $this->queryKey($entityClass, $queryKey);
        $entry = $this->queries[$fullKey] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($this->isExpired($entry['expiresAt'])) {
            unset($this->queries[$fullKey], $this->queryClassIndex[$entityClass][$fullKey]);

            return null;
        }

        return $entry['rows'];
    }

    /**
     * Invalidate all cached query results for a given entity class.
     *
     * Called automatically by evictRow() and evictAll().
     *
     * @param class-string $entityClass
     */
    public function evictQueryResults(string $entityClass): void
    {
        foreach (array_keys($this->queryClassIndex[$entityClass] ?? []) as $key) {
            unset($this->queries[$key]);
        }
        unset($this->queryClassIndex[$entityClass]);
    }

    /**
     * Number of currently-stored row entries.
     */
    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * Number of currently-stored query result entries.
     */
    public function queryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Build the deterministic row key.
     *
     * @param class-string $entityClass
     */
    private function rowKey(string $entityClass, int|string $identifier): string
    {
        return $entityClass . '::' . (string) $identifier;
    }

    /**
     * Build the deterministic query result key.
     *
     * @param class-string $entityClass
     */
    private function queryKey(string $entityClass, string $queryKey): string
    {
        return $entityClass . '::' . $queryKey;
    }

    /**
     * Compute the expiry timestamp for an entry given its TTL override.
     */
    private function expiresAt(?int $ttlSeconds): ?float
    {
        $ttl = $ttlSeconds ?? $this->defaultTtlSeconds;

        return $ttl !== null ? microtime(true) + $ttl : null;
    }

    /**
     * Whether an entry with the given expiry timestamp is expired.
     */
    private function isExpired(?float $expiresAt): bool
    {
        return $expiresAt !== null && microtime(true) > $expiresAt;
    }
}
