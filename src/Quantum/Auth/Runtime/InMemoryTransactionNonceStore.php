<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\TransactionNonceStoreInterface;

final class InMemoryTransactionNonceStore implements TransactionNonceStoreInterface
{
    /**
     * @var array<string, NonceRecord>
     */
    private array $records = [];

    /**
     * @param array<string, mixed> $bindingClaims
     */
    public function issueNonce(int $ttlSeconds, array $bindingClaims = []): NonceRecord
    {
        $now = time();
        $nonceValue = bin2hex(random_bytes(16));
        $record = new NonceRecord(
            value: $nonceValue,
            issuedAt: $now,
            expiresAt: $now + max(1, $ttlSeconds),
            bindingClaims: $bindingClaims,
        );
        $this->records[$nonceValue] = $record;
        return $record;
    }

    /**
     * @param array<string, mixed> $expectedBindingClaims
     */
    public function validateNonce(string $nonceValue, array $expectedBindingClaims = []): NonceValidationResult
    {
        if ($nonceValue === '' || trim($nonceValue) === '') {
            return NonceValidationResult::fail('empty_nonce');
        }

        $record = $this->records[$nonceValue] ?? null;
        if (! $record instanceof NonceRecord) {
            $this->purgeExpired();
            return NonceValidationResult::fail('nonce_not_found_or_consumed');
        }

        $now = time();
        if ($record->expiresAt <= $now) {
            unset($this->records[$nonceValue]);
            $this->purgeExpired();
            return NonceValidationResult::fail('nonce_expired');
        }

        foreach ($expectedBindingClaims as $k => $expected) {
            $actual = $record->bindingClaims[$k] ?? null;
            if ($actual !== $expected) {
                return NonceValidationResult::fail('nonce_binding_claim_mismatch:' . $k);
            }
        }

        unset($this->records[$nonceValue]);
        $this->purgeExpired();
        return NonceValidationResult::ok($record);
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->records as $value => $record) {
            if ($record->expiresAt <= $now) {
                unset($this->records[$value]);
            }
        }
    }
}
