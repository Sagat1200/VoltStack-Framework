<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\CacheContext;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Effect;
use Quantum\Cache\FileStore;
use Quantum\Cache\HitState;
use Quantum\Cache\LocalVersionAuthority;
use Quantum\Cache\MemoryStore;
use Quantum\Cache\NullStore;
use Quantum\Cache\Repository;

final class CacheRepositoryBaselineTest extends TestCase
{
    public function test_memory_store_preserves_null_and_lookup_reports_fresh_hit(): void
    {
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $repository = new Repository($store);

        self::assertTrue($repository->put('optional', null));
        self::assertTrue($repository->has('optional'));
        self::assertNull($repository->get('optional', 'fallback'));

        $lookup = $repository->lookup('optional');

        self::assertSame(HitState::Fresh, $lookup->state);
        self::assertNull($lookup->value);
        self::assertNotNull($lookup->metadata);
        self::assertSame('memory', $lookup->metadata?->sourceLevel);
        self::assertIsArray($store->payload('optional')['value'] ?? null);
    }

    public function test_memory_store_expires_entries_from_ttl(): void
    {
        $clock = $this->clockAt(1_700_000_000);
        $store = new MemoryStore($clock);
        $repository = new Repository($store);

        self::assertTrue($repository->put('greeting', 'hello', 10));
        self::assertSame('hello', $repository->get('greeting'));
        self::assertSame(HitState::Fresh, $repository->lookup('greeting')->state);

        $clock->advanceSeconds(11);

        self::assertFalse($repository->has('greeting'));
        self::assertSame('fallback', $repository->get('greeting', 'fallback'));
        self::assertSame(HitState::Miss, $repository->lookup('greeting')->state);
    }

    public function test_lookup_metadata_exposes_age_and_remaining_ttl(): void
    {
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(new MemoryStore($clock), clock: $clock);

        self::assertTrue($repository->put('profile', ['name' => 'Volt'], 10));

        $clock->advanceSeconds(3);
        $lookup = $repository->lookup('profile');

        self::assertSame(HitState::Fresh, $lookup->state);
        self::assertSame(3_000, $lookup->metadata?->ageMs);
        self::assertSame(7_000, $lookup->metadata?->ttlRemainingMs);
        self::assertSame(1_700_000_003_000, $lookup->metadata?->observedAtMs);
    }

    public function test_null_store_discards_writes_but_stays_operational(): void
    {
        $repository = new Repository(new NullStore());

        self::assertTrue($repository->put('banner', ['active' => true], 60));
        self::assertFalse($repository->has('banner'));
        self::assertSame('fallback', $repository->get('banner', 'fallback'));
        self::assertSame(HitState::Miss, $repository->lookup('banner')->state);
    }

    public function test_file_store_lookup_exposes_file_source_metadata(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-file-' . uniqid('', true);
        mkdir($directory, 0777, true);

        try {
            $store = new FileStore($directory, 'voltstack', $this->clockAt(1_700_000_000));
            $repository = new Repository($store);

            self::assertTrue($repository->put('page.home', ['html' => true], 60));

            $lookup = $repository->lookup('page.home');

            self::assertSame(HitState::Fresh, $lookup->state);
            self::assertSame(['html' => true], $lookup->value);
            self::assertNotNull($lookup->metadata);
            self::assertSame('file', $lookup->metadata?->sourceLevel);
            self::assertIsArray($store->payload('page.home')['value'] ?? null);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    public function test_repository_reads_legacy_raw_payloads_without_envelope(): void
    {
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $store->put('legacy', ['raw' => true], 60);

        $repository = new Repository($store);

        self::assertSame(['raw' => true], $repository->get('legacy'));
        self::assertSame(HitState::Fresh, $repository->lookup('legacy')->state);
    }

    public function test_repository_clear_rotates_local_version_scope_when_authority_exists(): void
    {
        $authority = new LocalVersionAuthority();
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $repository = new Repository(
            $store,
            'catalog',
            60,
            null,
            $authority,
            'catalog',
        );

        self::assertTrue($repository->put('product:42', ['name' => 'A']));
        self::assertSame(['name' => 'A'], $repository->get('product:42'));
        self::assertSame('v1', $repository->lookup('product:42')->metadata?->versions['namespace'] ?? null);

        self::assertTrue($repository->clear());
        self::assertSame('missing', $repository->get('product:42', 'missing'));

        self::assertTrue($repository->put('product:42', ['name' => 'B']));
        self::assertSame(['name' => 'B'], $repository->get('product:42'));
        self::assertSame('v2', $repository->lookup('product:42')->metadata?->versions['namespace'] ?? null);
    }

    public function test_tagged_repository_tracks_tag_versions_and_invalidates_only_matching_tag(): void
    {
        $authority = new LocalVersionAuthority();
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $repository = new Repository(
            $store,
            'catalog',
            60,
            null,
            $authority,
            'catalog',
        );

        $featured = $repository->tags(['featured']);
        $seasonal = $repository->tags(['seasonal']);

        self::assertTrue($featured->put('product:42', ['name' => 'A']));
        self::assertTrue($seasonal->put('product:42', ['name' => 'B']));
        self::assertSame(['name' => 'A'], $featured->get('product:42'));
        self::assertSame(['name' => 'B'], $seasonal->get('product:42'));
        self::assertSame('v1', $featured->lookup('product:42')->metadata?->versions['tag:featured'] ?? null);

        self::assertTrue($repository->invalidateTags(['featured']));

        self::assertSame('missing', $featured->get('product:42', 'missing'));
        self::assertSame(['name' => 'B'], $seasonal->get('product:42'));
        self::assertTrue($featured->put('product:42', ['name' => 'C']));
        self::assertSame(['name' => 'C'], $featured->get('product:42'));
        self::assertSame('v2', $featured->lookup('product:42')->metadata?->versions['tag:featured'] ?? null);
    }

    public function test_dependencies_alias_reuses_tag_namespace_mechanism(): void
    {
        $authority = new LocalVersionAuthority();
        $store = new MemoryStore($this->clockAt(1_700_000_000));
        $repository = new Repository(
            $store,
            'catalog',
            60,
            null,
            $authority,
            'catalog',
        );

        $dependent = $repository->dependencies(['tenant:42']);

        self::assertTrue($dependent->put('dashboard', 'ready'));
        self::assertSame('ready', $dependent->get('dashboard'));
        self::assertTrue($repository->invalidateDependencies(['tenant:42']));
        self::assertSame('missing', $dependent->get('dashboard', 'missing'));
    }

    public function test_repository_receipts_report_applied_effect_for_successful_writes(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        $put = $repository->putReceipt('foo', 'bar', 60);
        $forever = $repository->foreverReceipt('baz', 'qux');
        $forget = $repository->forgetReceipt('baz');

        self::assertSame(Effect::Applied, $put->effect);
        self::assertSame(Effect::Applied, $forever->effect);
        self::assertSame(Effect::Applied, $forget->effect);
        self::assertNotSame('', $put->operationId);
        self::assertSame('put', $put->details['operation'] ?? null);
        self::assertSame('foo', $put->details['key'] ?? null);
        self::assertNotNull($put->writeId);
        self::assertSame('forever', $forever->details['ttl']['mode'] ?? null);
    }

    public function test_put_receipt_reports_immediate_expiration_for_non_positive_ttl(): void
    {
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(new MemoryStore($clock), clock: $clock);

        $zero = $repository->putReceipt('ephemeral-zero', 'gone', 0);
        $negative = $repository->putReceipt('ephemeral-negative', 'gone', -5);

        self::assertSame(Effect::Applied, $zero->effect);
        self::assertSame(Effect::Applied, $negative->effect);
        self::assertTrue($zero->details['expired_immediately'] ?? false);
        self::assertTrue($negative->details['expired_immediately'] ?? false);
        self::assertNull($zero->writeId);
        self::assertSame('fallback', $repository->get('ephemeral-zero', 'fallback'));
        self::assertSame('fallback', $repository->get('ephemeral-negative', 'fallback'));
    }

    public function test_tagged_put_receipt_includes_scope_tags_and_normalized_key(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            ['featured'],
            $clock,
        );

        $receipt = $repository->putReceipt('product:42', ['name' => 'A'], 30);

        self::assertSame(Effect::Applied, $receipt->effect);
        self::assertSame('catalog', $receipt->details['scope'] ?? null);
        self::assertSame(['featured'], $receipt->details['tags'] ?? null);
        self::assertStringContainsString('tag[featured=v1]', (string) ($receipt->details['normalized_key'] ?? ''));
        self::assertSame('relative', $receipt->details['ttl']['mode'] ?? null);
        self::assertSame(30, $receipt->details['ttl']['remaining_seconds'] ?? null);
        self::assertNotNull($receipt->writeId);
    }

    public function test_cache_context_normalizes_prefix_scope_and_tags(): void
    {
        $context = CacheContext::from(
            keyPrefix: ' tenant/42\\catalog::pages ',
            versionScope: ' tenant:42 ',
            tags: [' featured ', 'seasonal', 'featured'],
            defaultTtl: 15,
        );

        self::assertSame('tenant:42:catalog:pages', $context->keyPrefix);
        self::assertSame('tenant:42', $context->versionScope);
        self::assertSame(['featured', 'seasonal'], $context->tags);
        self::assertSame(15, $context->defaultTtl);
    }

    public function test_repository_with_context_composes_prefix_scope_tags_and_default_ttl(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            [],
            $clock,
        );

        $scoped = $repository->withContext(CacheContext::from(
            keyPrefix: ['tenant', '42', 'feed'],
            versionScope: 'tenant:42',
            tags: ['featured'],
            defaultTtl: 5,
        ));

        $receipt = $scoped->putReceipt('page.home', ['ok' => true]);

        self::assertSame('catalog:tenant:42:feed', $scoped->context()->keyPrefix);
        self::assertSame('tenant:42', $scoped->context()->versionScope);
        self::assertSame(['featured'], $scoped->context()->tags);
        self::assertSame(5, $scoped->context()->defaultTtl);
        self::assertSame('catalog:tenant:42:feed', $receipt->details['context']['key_prefix'] ?? null);
        self::assertSame('tenant:42', $receipt->details['context']['version_scope'] ?? null);
        self::assertStringContainsString('catalog:tenant:42:feed:page.home', (string) ($receipt->details['normalized_key'] ?? ''));

        $clock->advanceSeconds(6);

        self::assertSame('missing', $scoped->get('page.home', 'missing'));
    }

    public function test_repository_namespace_creates_a_subnamespace_without_replacing_scope_or_tags(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            ['featured'],
            $clock,
        );

        $namespaced = $repository->namespace(['tenant', '42'])->namespace('feed/home');
        $receipt = $namespaced->putReceipt('page.hero', ['ok' => true], 15);

        self::assertSame('catalog:tenant:42:feed:home', $namespaced->context()->keyPrefix);
        self::assertSame('catalog', $namespaced->context()->versionScope);
        self::assertSame(['featured'], $namespaced->context()->tags);
        self::assertSame(60, $namespaced->context()->defaultTtl);
        self::assertStringContainsString('catalog:tenant:42:feed:home:page.hero', (string) ($receipt->details['normalized_key'] ?? ''));
        self::assertSame('tag_invalidation', $receipt->details['diagnostics']['clear_strategy'] ?? null);
    }

    public function test_repository_diagnostics_exposes_context_capabilities_versions_and_store_snapshot(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            ['featured'],
            $clock,
        );

        $diagnostics = $repository->diagnostics();

        self::assertSame('memory', $diagnostics->sourceLevel);
        self::assertSame('catalog', $diagnostics->context->keyPrefix);
        self::assertSame('catalog', $diagnostics->context->versionScope);
        self::assertSame(['featured'], $diagnostics->context->tags);
        self::assertSame(60, $diagnostics->context->defaultTtl);
        self::assertSame(40, strlen($diagnostics->contextFingerprint));
        self::assertTrue($diagnostics->capabilities['inspectable'] ?? false);
        self::assertTrue($diagnostics->capabilities['scoped_versions'] ?? false);
        self::assertTrue($diagnostics->capabilities['tag_versions'] ?? false);
        self::assertSame('v1', $diagnostics->versions['namespace'] ?? null);
        self::assertSame('v1', $diagnostics->versions['tag:featured'] ?? null);
        self::assertSame('tag[featured=v1]:v1:catalog', $diagnostics->storageNamespace);
        self::assertSame('catalog', $diagnostics->invalidationScopes['namespace'] ?? null);
        self::assertSame('catalog.tag.featured', $diagnostics->invalidationScopes['tags']['featured'] ?? null);
        self::assertSame(MemoryStore::class, $diagnostics->store['class'] ?? null);
        self::assertSame('tag_invalidation', $diagnostics->clearStrategy);
        self::assertSame(1_700_000_000_000, $diagnostics->observedAtMs);
    }

    public function test_receipt_details_include_repository_diagnostics_snapshot(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            ['featured'],
            $clock,
        );

        $receipt = $repository->putReceipt('product:42', ['name' => 'A'], 30);

        self::assertSame('memory', $receipt->details['diagnostics']['source_level'] ?? null);
        self::assertSame('catalog', $receipt->details['diagnostics']['context']['key_prefix'] ?? null);
        self::assertSame(40, strlen((string) ($receipt->details['diagnostics']['context_fingerprint'] ?? '')));
        self::assertSame('tag[featured=v1]:v1:catalog', $receipt->details['diagnostics']['storage_namespace'] ?? null);
        self::assertSame('catalog.tag.featured', $receipt->details['diagnostics']['invalidation_scopes']['tags']['featured'] ?? null);
        self::assertSame('tag_invalidation', $receipt->details['diagnostics']['clear_strategy'] ?? null);
        self::assertTrue($receipt->details['diagnostics']['capabilities']['inspectable'] ?? false);
        self::assertSame(MemoryStore::class, $receipt->details['diagnostics']['store']['class'] ?? null);
    }

    public function test_repository_explain_reports_normalized_key_lookup_and_diagnostics_without_payload(): void
    {
        $authority = new LocalVersionAuthority();
        $clock = $this->clockAt(1_700_000_000);
        $repository = new Repository(
            new MemoryStore($clock),
            'catalog',
            60,
            null,
            $authority,
            'catalog',
            ['featured'],
            $clock,
        );

        self::assertTrue($repository->put('product:42', ['name' => 'A'], 30));

        $explain = $repository->explain('product:42');

        self::assertSame('product:42', $explain['key'] ?? null);
        self::assertStringContainsString('tag[featured=v1]:v1:catalog:product:42', (string) ($explain['normalized_key'] ?? ''));
        self::assertSame('fresh', $explain['lookup']['state'] ?? null);
        self::assertArrayHasKey('miss_reason', $explain['lookup']);
        self::assertNull($explain['lookup']['miss_reason']);
        self::assertFalse($explain['lookup']['value_exposed'] ?? true);
        self::assertArrayNotHasKey('value', $explain['lookup']);
        self::assertSame('memory', $explain['lookup']['metadata']['source_level'] ?? null);
        self::assertSame('v1', $explain['lookup']['metadata']['versions']['namespace'] ?? null);
        self::assertSame('lookup', $explain['plan']['operation'] ?? null);
        self::assertSame('product:42', $explain['plan']['logical_key'] ?? null);
        self::assertStringContainsString('tag[featured=v1]:v1:catalog:product:42', (string) ($explain['plan']['normalized_key'] ?? ''));
        self::assertSame('tag_invalidation', $explain['plan']['clear_strategy'] ?? null);
        self::assertSame('catalog.tag.featured', $explain['plan']['invalidation_scopes']['tags']['featured'] ?? null);
        self::assertSame('relative', $explain['policies']['default_ttl']['mode'] ?? null);
        self::assertSame(60, $explain['policies']['default_ttl']['remaining_seconds'] ?? null);
        self::assertFalse($explain['policies']['uses_marshaller'] ?? true);
        self::assertTrue($explain['policies']['uses_namespace_versions'] ?? false);
        self::assertTrue($explain['policies']['uses_tag_versions'] ?? false);
        self::assertTrue($explain['policies']['inspectable_store'] ?? false);
        self::assertSame('memory', $explain['policies']['source_level'] ?? null);
        self::assertSame('tag[featured=v1]:v1:catalog', $explain['diagnostics']['storage_namespace'] ?? null);
        self::assertSame('catalog.tag.featured', $explain['diagnostics']['invalidation_scopes']['tags']['featured'] ?? null);
    }

    public function test_repository_explain_reports_miss_reason_without_payload(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        $explain = $repository->explain('missing-key');

        self::assertSame('missing-key', $explain['key'] ?? null);
        self::assertSame('missing-key', $explain['normalized_key'] ?? null);
        self::assertSame('miss', $explain['lookup']['state'] ?? null);
        self::assertSame('not_found', $explain['lookup']['miss_reason'] ?? null);
        self::assertArrayHasKey('metadata', $explain['lookup']);
        self::assertNull($explain['lookup']['metadata']);
        self::assertFalse($explain['lookup']['value_exposed'] ?? true);
        self::assertSame('lookup', $explain['plan']['operation'] ?? null);
        self::assertSame('missing-key', $explain['plan']['normalized_key'] ?? null);
        self::assertSame('store_flush', $explain['plan']['clear_strategy'] ?? null);
        self::assertSame('forever', $explain['policies']['default_ttl']['mode'] ?? null);
        self::assertFalse($explain['policies']['uses_namespace_versions'] ?? true);
        self::assertFalse($explain['policies']['uses_tag_versions'] ?? true);
        self::assertTrue($explain['policies']['inspectable_store'] ?? false);
    }

    public function test_pull_returns_value_and_removes_the_key(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        self::assertTrue($repository->put('once', 'token', 60));
        self::assertSame('token', $repository->pull('once'));
        self::assertFalse($repository->has('once'));
        self::assertSame('fallback', $repository->pull('once', 'fallback'));
    }

    public function test_many_returns_values_for_each_requested_key(): void
    {
        $repository = new Repository(new MemoryStore($this->clockAt(1_700_000_000)));

        self::assertTrue($repository->put('a', 1, 60));
        self::assertTrue($repository->put('b', null, 60));

        self::assertSame(
            ['a' => 1, 'b' => null, 'c' => 'missing'],
            $repository->many(['a', 'b', 'c'], 'missing'),
        );
    }

    private function clockAt(int $unixSeconds): object
    {
        return new class($unixSeconds) implements ClockInterface {
            public function __construct(
                private int $unixSeconds,
            ) {}

            public function nowUnixSeconds(): int
            {
                return $this->unixSeconds;
            }

            public function nowUnixMilliseconds(): int
            {
                return $this->unixSeconds * 1000;
            }

            public function monotonicNanoseconds(): int
            {
                return $this->unixSeconds * 1_000_000_000;
            }

            public function advanceSeconds(int $seconds): void
            {
                $this->unixSeconds += $seconds;
            }
        };
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
