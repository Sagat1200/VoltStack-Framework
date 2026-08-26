<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseRemoteReplayValidationResult;

interface DatabaseRemoteReplayValidatorInterface
{
    /**
     * @param array<string, mixed> $confirmationEvidence
     */
    public function validate(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        array $confirmationEvidence,
    ): DatabaseRemoteReplayValidationResult;
}