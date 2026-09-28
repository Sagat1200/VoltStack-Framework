<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Manifest\InMemoryAuthorizationManifestStore;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;
use Quantum\Authorization\Metadata\AuthorizationRequirement;

final class InMemoryAuthorizationManifestStoreTest extends TestCase
{
    public function test_it_stores_and_retrieves_payloads_by_fingerprint(): void
    {
        $store = new InMemoryAuthorizationManifestStore();
        $payload = new AuthorizationMetadataPayload(
            public: true,
            requirements: [
                new AuthorizationRequirement('documents.view', 'document', 'class'),
            ],
        );

        self::assertFalse($store->has($payload->fingerprint()));

        $store->put($payload);

        self::assertTrue($store->has($payload->fingerprint()));
        $retrieved = $store->get($payload->fingerprint());
        self::assertInstanceOf(AuthorizationMetadataPayload::class, $retrieved);
        self::assertTrue($retrieved->public());
        self::assertSame('documents.view', $retrieved->requirements()[0]->ability());
    }

    public function test_it_forgets_and_clears_payloads(): void
    {
        $store = new InMemoryAuthorizationManifestStore();
        $a = new AuthorizationMetadataPayload(
            public: true,
            requirements: [new AuthorizationRequirement('a.view', null, 'class')],
        );
        $b = new AuthorizationMetadataPayload(
            public: false,
            requirements: [new AuthorizationRequirement('b.view', null, 'class')],
        );

        $store->put($a);
        $store->put($b);

        self::assertTrue($store->has($a->fingerprint()));
        self::assertTrue($store->has($b->fingerprint()));

        $store->forget($a->fingerprint());

        self::assertFalse($store->has($a->fingerprint()));
        self::assertTrue($store->has($b->fingerprint()));

        $cleared = $store->clear();

        self::assertSame(1, $cleared);
        self::assertFalse($store->has($b->fingerprint()));
        self::assertNull($store->get($b->fingerprint()));
    }
}
