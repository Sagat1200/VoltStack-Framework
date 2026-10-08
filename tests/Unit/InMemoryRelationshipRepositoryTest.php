<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Relationship\InMemoryRelationshipRepository;
use Quantum\Cache\LocalVersionAuthority;

final class InMemoryRelationshipRepositoryTest extends TestCase
{
    public function test_it_matches_scalar_and_object_resources(): void
    {
        $document = (object) ['id' => 'doc-1'];
        $repository = new InMemoryRelationshipRepository([
            ['principal_id' => 'user-1', 'relation' => 'owner', 'resource' => 'invoice-1'],
            ['principal_id' => 'user-1', 'relation' => 'editor', 'resource' => $document],
        ]);

        self::assertTrue($repository->hasRelationship('user-1', 'owner', 'invoice-1'));
        self::assertTrue($repository->hasRelationship('user-1', 'editor', $document));
        self::assertFalse($repository->hasRelationship('user-1', 'viewer', $document));
    }

    public function test_it_falls_back_through_parent_scopes_and_global(): void
    {
        $repository = new InMemoryRelationshipRepository([
            ['principal_id' => 'user-1', 'relation' => 'owner', 'resource' => 'doc-1', 'scope' => 'tenant:acme'],
            ['principal_id' => 'user-2', 'relation' => 'owner', 'resource' => 'doc-2'],
        ]);

        self::assertTrue($repository->hasRelationship('user-1', 'owner', 'doc-1', new Scope('tenant:acme:workspace:blue')));
        self::assertTrue($repository->hasRelationship('user-2', 'owner', 'doc-2', new Scope('tenant:acme')));
        self::assertFalse($repository->hasRelationship('user-1', 'owner', 'doc-1', new Scope('tenant:other')));
    }

    public function test_it_lists_and_revokes_relationships_by_resource_key(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());
        $repository = new InMemoryRelationshipRepository([
            ['principal_id' => 'user-1', 'relation' => 'owner', 'resource' => 'doc-1', 'scope' => 'tenant:acme'],
            ['principal_id' => 'user-1', 'relation' => 'viewer', 'resource' => 'doc-2', 'scope' => 'global'],
        ], $consistency);

        $listed = $repository->listRelationships([
            'principal_id' => 'user-1',
            'relation' => 'owner',
            'scope' => 'tenant:acme',
        ]);

        self::assertCount(1, $listed);
        self::assertSame('string:doc-1', $listed[0]['resource_key']);
        $before = $consistency->relationshipVersion('user-1', 'tenant:acme');
        self::assertTrue($repository->revokeRelationshipByKey('user-1', 'owner', 'string:doc-1', 'tenant:acme'));
        self::assertFalse($repository->hasRelationship('user-1', 'owner', 'doc-1', 'tenant:acme'));
        self::assertNotSame($before, $consistency->relationshipVersion('user-1', 'tenant:acme'));
    }
}
