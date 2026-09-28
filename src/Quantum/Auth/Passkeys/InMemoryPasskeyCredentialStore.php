<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;

/**
 * @internal skeleton V1 — no-op criptografía para 082
 */
final class InMemoryPasskeyCredentialStore implements PasskeyCredentialStoreInterface
{
    /**
     * @var array<string, PasskeyCredentialRecord>
     */
    private array $records = [];

    public function findByCredentialId(string $credentialId): ?PasskeyCredentialRecord
    {
        if (trim($credentialId) === '') {
            return null;
        }
        return $this->records[$credentialId] ?? null;
    }

    /**
     * @return list<PasskeyCredentialRecord>
     */
    public function listForUserHandle(string $userHandle): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record->userHandle === $userHandle) {
                $out[] = $record;
            }
        }
        return $out;
    }

    public function save(PasskeyCredentialRecord $record): void
    {
        if (trim($record->credentialId) === '') {
            return;
        }
        $this->records[$record->credentialId] = $record;
    }

    public function revoke(string $credentialId): bool
    {
        if (! isset($this->records[$credentialId])) {
            return false;
        }
        unset($this->records[$credentialId]);
        return true;
    }
}
