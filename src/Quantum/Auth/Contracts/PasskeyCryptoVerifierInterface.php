<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Passkeys\AssertionResult;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Passkeys\RelyingPartyConfig;

interface PasskeyCryptoVerifierInterface
{
    /**
     * @param array<string, mixed> $registrationResponse Mismo shape que completeRegistration() de ceremony.
     * @throws \Quantum\Auth\Passkeys\Exceptions\AttestationVerificationFailedException
     * @throws \Quantum\Auth\Passkeys\Exceptions\UnsupportedCoseAlgorithmException
     */
    public function verifyAttestation(array $registrationResponse, RelyingPartyConfig $rp): PasskeyCredentialRecord;

    /**
     * @param array<string, mixed> $assertionResponse
     * @throws \Quantum\Auth\Passkeys\Exceptions\AssertionVerificationFailedException
     * @throws \Quantum\Auth\Passkeys\Exceptions\UnsupportedCoseAlgorithmException
     * @throws \Quantum\Auth\Passkeys\Exceptions\CounterReplayException
     */
    public function verifyAssertion(array $assertionResponse, PasskeyCredentialRecord $record, int $storedCounter): AssertionResult;
}
