<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Relationship\RemoteCacheRelationshipRepository;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\MemoryStore;

final class RemoteCacheRelationshipRepositoryTest extends TestCase
{
    public function test_relationships_round_trip_over_shared_store_and_scope_inheritance(): void
    {
        $store = new MemoryStore();
        $consistencyStore = new MemoryStore();
        $consistency = new VersionedAuthorizationConsistency(
            new CacheVersionAuthority($consistencyStore, 'rels.consistency'),
            'rels',
        );

        $first = new RemoteCacheRelationshipRepository($store, 'acme.rels', $consistency);
        $second = new RemoteCacheRelationshipRepository($store, 'acme.rels', $consistency);

        $resourceA = (object) ['id' => 'doc-1'];
        $resourceB = (object) ['id' => 'doc-2'];

        self::assertTrue($first->storeRelationship('user-1', 'owner', $resourceA, 'org:acme:team'));
        self::assertFalse($first->storeRelationship('user-1', 'owner', $resourceA, 'org:acme:team'));
        self::assertTrue($first->storeRelationship('user-1', 'viewer', $resourceB, 'org:acme'));

        // Exact match
        self::assertTrue($second->hasRelationship('user-1', 'owner', $resourceA, 'org:acme:team'));
        // Scope inheritance towards global
        self::assertTrue($second->hasRelationship('user-1', 'owner', $resourceA, 'org:acme:team:sub'));
        // Wrong relation
        self::assertFalse($second->hasRelationship('user-1', 'viewer', $resourceA, 'org:acme:team'));
        // Wrong resource
        self::assertFalse($second->hasRelationship('user-1', 'owner', $resourceB, 'org:acme:team'));

        $listed = $second->listRelationships(['principal_id' => 'user-1']);
        self::assertCount(2, $listed);

        $tuple = $listed[0];
        $resourceKey = $tuple['resource_key'];

        self::assertTrue($second->revokeRelationshipByKey('user-1', 'owner', $resourceKey, 'org:acme:team'));
        self::assertFalse($first->hasRelationship('user-1', 'owner', $resourceA, 'org:acme:team'));

        $inspect = $consistency->inspect();
        self::assertSame(
            'relationships.revoke',
            $inspect['last_bump_reasons_by_segment']['relationships.principal_scope'] ?? null,
        );
    }

    public function test_list_filters_by_relation_and_scope(): void
    {
        $store = new MemoryStore();
        $repository = new RemoteCacheRelationshipRepository($store, 'filtered.rels');

        $repository->storeRelationship('u1', 'owner', 'res-1', 'org:acme');
        $repository->storeRelationship('u1', 'viewer', 'res-2', 'org:acme');
        $repository->storeRelationship('u1', 'owner', 'res-3', 'org:other');

        $onlyOwners = $repository->listRelationships(['relation' => 'owner']);
        self::assertCount(2, $onlyOwners);

        $onlyAcme = $repository->listRelationships(['scope' => 'org:acme']);
        self::assertCount(2, $onlyAcme);

        $both = $repository->listRelationships(['relation' => 'viewer', 'scope' => 'org:acme']);
        self::assertCount(1, $both);
        self::assertSame('res-2', str_replace('string:', '', $both[0]['resource_key']));
    }
}
