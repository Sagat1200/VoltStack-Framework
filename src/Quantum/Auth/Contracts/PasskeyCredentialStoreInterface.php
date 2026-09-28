<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Passkeys\PasskeyCredentialRecord;

interface PasskeyCredentialStoreInterface
{
    public function findByCredentialId(string $credentialId): ?PasskeyCredentialRecord;

    /**
     * @return list<PasskeyCredentialRecord>
     */
    public function listForUserHandle(string $userHandle): array;

    public function save(PasskeyCredentialRecord $record): void;

    public function revoke(string $credentialId): bool;
}
