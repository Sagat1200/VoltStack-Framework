<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Relationship\InMemoryRelationshipRepository;

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
}
