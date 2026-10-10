<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryCodeStoreInterface;
use Quantum\Auth\Identity\IdentityReference;

class InMemoryRecoveryCodeStore implements RecoveryCodeStoreInterface
{
    /**
     * @var array<string, array<string, RecoveryCode>> indexados por
     *   `sprintf("%s|%s", $identity->type, (string)$identity->identifier)`
     *   y luego `code_hash`
     */
    private array $records = [];

    private function bucket(IdentityReference $identity): string
    {
        return sprintf('%s|%s', $identity->type, (string) $identity->identifier);
    }

    public function attachBatch(IdentityReference $identity, iterable $codes): array
    {
        $bucket = $this->bucket($identity);
        if (! isset($this->records[$bucket])) {
            $this->records[$bucket] = [];
        }

        $persisted = [];
        foreach ($codes as $code) {
            if (! $code instanceof RecoveryCode) {
                continue;
            }

            $stripped = new RecoveryCode(
                $code->codeHash,
                $code->identityIdentifier,
                $code->identityType,
                $code->issuedAt,
                $code->expiresAt,
                $code->consumedAt,
                $code->metadata,
                null,
            );
            $this->records[$bucket][$code->codeHash] = $stripped;
            $persisted[] = $stripped;
        }

        return $persisted;
    }

    public function listForIdentity(IdentityReference $identity): array
    {
        $bucket = $this->bucket($identity);
        if (! isset($this->records[$bucket])) {
            return [];
        }

        return array_values($this->records[$bucket]);
    }

    public function findUsableByCode(IdentityReference $identity, string $plainCode, ?int $now = null): ?RecoveryCode
    {
        $now ??= time();
        $bucket = $this->bucket($identity);
        if (! isset($this->records[$bucket])) {
            return null;
        }

        foreach ($this->records[$bucket] as $record) {
            if (! $record->isUsable($now)) {
                continue;
            }

            $isMatch = str_starts_with($record->codeHash, '$2') || str_starts_with($record->codeHash, '$argon')
                ? password_verify($plainCode, $record->codeHash)
                : hash_equals($record->codeHash, hash('sha256', $plainCode));

            if ($isMatch) {
                return $record;
            }
        }

        return null;
    }

    public function consume(RecoveryCode $code, ?int $now = null): ?RecoveryCode
    {
        $now ??= time();
        $identity = new IdentityReference(
            new \Quantum\Auth\Identity\IdentityIdentifier($code->identityIdentifier),
            $code->identityType,
        );
        $bucket = $this->bucket($identity);
        if (! isset($this->records[$bucket][$code->codeHash])) {
            return null;
        }

        $stored = $this->records[$bucket][$code->codeHash];
        if (! $stored->isUsable($now)) {
            return null;
        }

        $consumed = $stored->withConsumedAt($now);
        $this->records[$bucket][$code->codeHash] = $consumed;

        return $consumed;
    }

    public function rotateForIdentity(IdentityReference $identity, ?int $now = null): int
    {
        $now ??= time();
        $bucket = $this->bucket($identity);
        if (! isset($this->records[$bucket])) {
            return 0;
        }

        $inactivated = 0;
        foreach ($this->records[$bucket] as $hash => $code) {
            if (! $code->isConsumed()) {
                $this->records[$bucket][$hash] = $code->withConsumedAt($now);
                ++$inactivated;
            }
        }

        return $inactivated;
    }
}
