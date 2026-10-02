<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Tokens\OpaqueRefreshToken;
use Quantum\Auth\Tokens\TokenId;

interface RefreshTokenRotationStoreInterface
{
    public function findRefreshToken(string $tokenId): ?OpaqueRefreshToken;

    /**
     * @return array{consumed:bool, already_consumed:bool, previous:?OpaqueRefreshToken}
     */
    public function consumeRefreshToken(string $tokenId, ?int $consumedAt = null): array;

    /**
     * @return array<int, OpaqueRefreshToken>
     */
    public function findRefreshTokensByFamilyId(string $familyId): array;

    public function revokeFamilyByReuse(string $familyId, ?int $reuseDetectedAt = null): int;

    public function markRotatedTo(string $parentRefreshTokenId, TokenId $nextRefreshId): void;
}
