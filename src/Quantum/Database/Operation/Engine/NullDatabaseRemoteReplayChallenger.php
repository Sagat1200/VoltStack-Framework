<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;

final class NullDatabaseRemoteReplayChallenger implements DatabaseRemoteReplayChallengerInterface
{
    public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse
    {
        return DatabaseRemoteReplayChallengeResponse::unavailable(
            challenger: 'null_remote_replay_challenger',
            message: 'Remote replay challenge transport is not configured.',
            details: [
                'challenge_id' => $request->challengeId,
                'challenge_nonce' => $request->challengeNonce,
                'source_node_id' => $request->sourceNodeId,
                'current_node_id' => $request->currentNodeId,
                'operation_fingerprint' => $request->operationFingerprint,
                'confirmation_fingerprint' => $request->confirmationFingerprint,
            ],
        );
    }
}
