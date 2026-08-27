<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;

interface DatabaseRemoteReplayChallengerInterface
{
    public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse;
}