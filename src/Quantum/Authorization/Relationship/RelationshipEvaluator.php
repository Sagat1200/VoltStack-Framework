<?php

declare(strict_types=1);

namespace Quantum\Authorization\Relationship;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Core\AuthorizationRequest;

final readonly class RelationshipEvaluator
{
    public function __construct(
        private RelationshipRepositoryInterface $relationships,
    ) {}

    /**
     * @param array{relation?:mixed} $requirement
     */
    public function evaluate(
        AuthorizationRequest $request,
        array $requirement,
        Scope $scope,
    ): bool {
        $relation = $requirement['relation'] ?? null;

        if (! is_string($relation) || trim($relation) === '') {
            return true;
        }

        $subject = $request->subject();

        if (! $subject->present()) {
            return false;
        }

        return $this->relationships->hasRelationship(
            principalId: $request->principal()->id(),
            relation: trim($relation),
            resource: $subject->value(),
            scope: $scope,
        );
    }
}
