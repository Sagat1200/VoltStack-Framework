<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayValidatorInterface;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseRemoteReplayValidationResult;

final class NullDatabaseRemoteReplayValidator implements DatabaseRemoteReplayValidatorInterface
{
    public function validate(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        array $confirmationEvidence,
    ): DatabaseRemoteReplayValidationResult {
        return DatabaseRemoteReplayValidationResult::unavailable(
            validator: 'null_remote_replay_validator',
            message: 'Active remote replay validation is not configured.',
            details: [
                'source_node_id' => $record->nodeId,
                'current_node_id' => $currentNodeId,
                'operation_fingerprint' => $plan->fingerprint,
            ],
        );
    }
}
