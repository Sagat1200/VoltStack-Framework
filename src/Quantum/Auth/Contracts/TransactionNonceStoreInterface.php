<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Runtime\NonceRecord;
use Quantum\Auth\Runtime\NonceValidationResult;

interface TransactionNonceStoreInterface
{
    /**
     * @param array<string, mixed> $bindingClaims
     */
    public function issueNonce(int $ttlSeconds, array $bindingClaims = []): NonceRecord;

    /**
     * @param array<string, mixed> $expectedBindingClaims
     */
    public function validateNonce(string $nonceValue, array $expectedBindingClaims = []): NonceValidationResult;
}
