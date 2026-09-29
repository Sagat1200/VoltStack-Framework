<?php

declare(strict_types=1);

namespace Quantum\Auth\Tokens;

use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use RuntimeException;

final class FileOpaqueTokenRepository implements OpaqueTokenRepositoryInterface
{
    private string $accessDirectory;
    private string $refreshDirectory;

    public function __construct(string $storageDirectory)
    {
        $storageDirectory = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($storageDirectory)), DIRECTORY_SEPARATOR);

        if ($storageDirectory === '') {
            throw new RuntimeException('FileOpaqueTokenRepository storage directory cannot be empty.');
        }

        $this->accessDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'access';
        $this->refreshDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'refresh';

        foreach ([$this->accessDirectory, $this->refreshDirectory] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                throw new RuntimeException(sprintf(
                    'Unable to create opaque token storage directory [%s].',
                    $dir,
                ));
            }
        }
    }

    public function findAccessToken(string $tokenId): ?OpaqueAccessToken
    {
        $path = $this->accessPath($tokenId);

        if (! is_file($path)) {
            return null;
        }

        $data = $this->readFile($path);

        return $data !== null ? $this->hydrateAccessToken($data) : null;
    }

    public function findRefreshToken(string $tokenId): ?OpaqueRefreshToken
    {
        $path = $this->refreshPath($tokenId);

        if (! is_file($path)) {
            return null;
        }

        $data = $this->readFile($path);

        return $data !== null ? $this->hydrateRefreshToken($data) : null;
    }

    public function saveAccessToken(OpaqueAccessToken $token): void
    {
        $payload = [
            'id' => $token->id->value,
            'reference_type' => $token->reference->type,
            'reference_identifier' => $token->reference->identifier->value,
            'issued_at' => $token->issuedAt,
            'expires_at' => $token->expiresAt,
            'client_id' => $token->clientId,
            'scopes' => $token->scopes,
            'refresh_token_id' => $token->refreshTokenId?->value,
            'attributes' => $token->attributes,
            'revoked' => $token->revoked,
        ];

        $this->writeFile($this->accessPath($token->id->value), $payload);
    }

    public function saveRefreshToken(OpaqueRefreshToken $token): void
    {
        $payload = [
            'id' => $token->id->value,
            'reference_type' => $token->reference->type,
            'reference_identifier' => $token->reference->identifier->value,
            'issued_at' => $token->issuedAt,
            'expires_at' => $token->expiresAt,
            'access_token_id' => $token->accessTokenId?->value,
            'client_id' => $token->clientId,
            'scopes' => $token->scopes,
            'attributes' => $token->attributes,
            'revoked' => $token->revoked,
            'consumed' => $token->consumed,
            'consumed_at' => $token->consumedAt,
            'rotated_to' => $token->rotatedTo?->value,
            'family_id' => $token->familyId,
        ];

        $this->writeFile($this->refreshPath($token->id->value), $payload);
    }

    public function revokeAccessToken(string $tokenId): bool
    {
        $existing = $this->findAccessToken($tokenId);

        if ($existing === null || $existing->revoked) {
            return $existing !== null && $existing->revoked;
        }

        $this->saveAccessToken(new OpaqueAccessToken(
            id: $existing->id,
            reference: $existing->reference,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            clientId: $existing->clientId,
            scopes: $existing->scopes,
            refreshTokenId: $existing->refreshTokenId,
            attributes: $existing->attributes,
            revoked: true,
        ));

        return true;
    }

    public function revokeRefreshToken(string $tokenId): bool
    {
        $existing = $this->findRefreshToken($tokenId);

        if ($existing === null || $existing->revoked) {
            return $existing !== null && $existing->revoked;
        }

        $this->saveRefreshToken(new OpaqueRefreshToken(
            id: $existing->id,
            reference: $existing->reference,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            accessTokenId: $existing->accessTokenId,
            clientId: $existing->clientId,
            scopes: $existing->scopes,
            attributes: $existing->attributes,
            revoked: true,
            consumed: $existing->consumed,
            consumedAt: $existing->consumedAt,
            rotatedTo: $existing->rotatedTo,
            familyId: $existing->familyId,
        ));

        return true;
    }

    public function revokeAllForIdentity(string $identityType, string $identityId): int
    {
        $revoked = 0;

        foreach ($this->listAccessTokensForIdentity($identityType, $identityId) as $token) {
            if (! $token->revoked && $this->revokeAccessToken($token->id->value)) {
                $revoked++;
            }
        }

        foreach ($this->listRefreshTokensForIdentity($identityType, $identityId) as $token) {
            if (! $token->revoked && $this->revokeRefreshToken($token->id->value)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    public function listAccessTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array
    {
        return $this->collect($this->accessDirectory, function (mixed $data) use ($identityType, $identityId): ?OpaqueAccessToken {
            $token = $this->hydrateAccessToken($data);

            return (
                $token->reference->type === $identityType
                && $token->reference->identifier->value === $identityId
            ) ? $token : null;
        });
    }

    public function listRefreshTokensForIdentity(string $identityType, string $identityId, ?int $now = null): array
    {
        return $this->collect($this->refreshDirectory, function (mixed $data) use ($identityType, $identityId): ?OpaqueRefreshToken {
            $token = $this->hydrateRefreshToken($data);

            return (
                $token->reference->type === $identityType
                && $token->reference->identifier->value === $identityId
            ) ? $token : null;
        });
    }

    /**
     * @template T
     *
     * @param callable(mixed): ?T $mapper
     *
     * @return array<int, T>
     */
    private function collect(string $directory, callable $mapper): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $entries = @scandir($directory);
        $result = [];

        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $data = $this->readFile($path);

            if ($data === null) {
                continue;
            }

            $mapped = $mapper($data);

            if ($mapped !== null) {
                $result[] = $mapped;
            }
        }

        return $result;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function readFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeFile(string $path, array $payload): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory [%s] for opaque token storage.', $dir));
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (@file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write opaque token file [%s].', $path));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateAccessToken(array $data): OpaqueAccessToken
    {
        $referenceType = is_string($data['reference_type'] ?? null) ? $data['reference_type'] : 'user';
        $referenceIdentifier = is_string($data['reference_identifier'] ?? null) ? $data['reference_identifier'] : '';
        $refreshTokenId = isset($data['refresh_token_id']) && is_string($data['refresh_token_id']) && trim($data['refresh_token_id']) !== ''
            ? new TokenId($data['refresh_token_id'])
            : null;
        $scopes = is_array($data['scopes'] ?? null) ? array_values(array_filter($data['scopes'], static fn (mixed $v): bool => is_string($v))) : [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

        return new OpaqueAccessToken(
            id: new TokenId((string) ($data['id'] ?? '')),
            reference: new IdentityReference(
                new IdentityIdentifier($referenceIdentifier),
                $referenceType,
            ),
            issuedAt: isset($data['issued_at']) && is_numeric($data['issued_at']) ? (int) $data['issued_at'] : time(),
            expiresAt: isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : PHP_INT_MAX,
            clientId: isset($data['client_id']) && is_string($data['client_id']) && trim($data['client_id']) !== '' ? $data['client_id'] : null,
            scopes: $scopes,
            refreshTokenId: $refreshTokenId,
            attributes: $attributes,
            revoked: isset($data['revoked']) && $data['revoked'] === true,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateRefreshToken(array $data): OpaqueRefreshToken
    {
        $referenceType = is_string($data['reference_type'] ?? null) ? $data['reference_type'] : 'user';
        $referenceIdentifier = is_string($data['reference_identifier'] ?? null) ? $data['reference_identifier'] : '';
        $accessTokenId = isset($data['access_token_id']) && is_string($data['access_token_id']) && trim($data['access_token_id']) !== ''
            ? new TokenId($data['access_token_id'])
            : null;
        $rotatedTo = isset($data['rotated_to']) && is_string($data['rotated_to']) && trim($data['rotated_to']) !== ''
            ? new TokenId($data['rotated_to'])
            : null;
        $scopes = is_array($data['scopes'] ?? null) ? array_values(array_filter($data['scopes'], static fn (mixed $v): bool => is_string($v))) : [];
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $familyId = isset($data['family_id']) && is_string($data['family_id']) && trim($data['family_id']) !== ''
            ? $data['family_id']
            : null;

        return new OpaqueRefreshToken(
            id: new TokenId((string) ($data['id'] ?? '')),
            reference: new IdentityReference(
                new IdentityIdentifier($referenceIdentifier),
                $referenceType,
            ),
            issuedAt: isset($data['issued_at']) && is_numeric($data['issued_at']) ? (int) $data['issued_at'] : time(),
            expiresAt: isset($data['expires_at']) && is_numeric($data['expires_at']) ? (int) $data['expires_at'] : PHP_INT_MAX,
            accessTokenId: $accessTokenId,
            clientId: isset($data['client_id']) && is_string($data['client_id']) && trim($data['client_id']) !== '' ? $data['client_id'] : null,
            scopes: $scopes,
            attributes: $attributes,
            revoked: isset($data['revoked']) && $data['revoked'] === true,
            consumed: isset($data['consumed']) && $data['consumed'] === true,
            consumedAt: isset($data['consumed_at']) && is_numeric($data['consumed_at']) ? (int) $data['consumed_at'] : null,
            rotatedTo: $rotatedTo,
            familyId: $familyId,
        );
    }

    public function consumeRefreshToken(string $tokenId, ?int $consumedAt = null): array
    {
        $existing = $this->findRefreshToken($tokenId);
        if (! $existing instanceof OpaqueRefreshToken) {
            return ['consumed' => false, 'already_consumed' => false, 'previous' => null];
        }
        if ($existing->consumed) {
            return ['consumed' => false, 'already_consumed' => true, 'previous' => $existing];
        }
        $consumedAt ??= time();
        $next = new OpaqueRefreshToken(
            id: $existing->id,
            reference: $existing->reference,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            accessTokenId: $existing->accessTokenId,
            clientId: $existing->clientId,
            scopes: $existing->scopes,
            attributes: $existing->attributes,
            revoked: $existing->revoked,
            consumed: true,
            consumedAt: $consumedAt,
            rotatedTo: $existing->rotatedTo,
            familyId: $existing->familyId,
        );
        $this->saveRefreshToken($next);
        return ['consumed' => true, 'already_consumed' => false, 'previous' => $existing];
    }

    public function findRefreshTokensByFamilyId(string $familyId): array
    {
        if ($familyId === '') {
            return [];
        }
        return $this->collect($this->refreshDirectory, function (mixed $data) use ($familyId): ?OpaqueRefreshToken {
            $token = $this->hydrateRefreshToken($data);
            return (is_string($token->familyId) && $token->familyId !== '' && hash_equals($familyId, $token->familyId))
                ? $token
                : null;
        });
    }

    public function revokeFamilyByReuse(string $familyId, ?int $reuseDetectedAt = null): int
    {
        if ($familyId === '') {
            return 0;
        }
        $revoked = 0;
        $reuseAttr = ['reuse_detected_at' => $reuseDetectedAt ?? time(), 'reuse_revoked' => true];
        $familyTokens = $this->findRefreshTokensByFamilyId($familyId);
        $visitedRefresh = [];
        $queue = $familyTokens;
        while ($queue !== []) {
            $refresh = array_shift($queue);
            if (! $refresh instanceof OpaqueRefreshToken) {
                continue;
            }
            $key = $refresh->id->value;
            if (isset($visitedRefresh[$key])) {
                continue;
            }
            $visitedRefresh[$key] = true;
            if (! $refresh->revoked) {
                $next = new OpaqueRefreshToken(
                    id: $refresh->id,
                    reference: $refresh->reference,
                    issuedAt: $refresh->issuedAt,
                    expiresAt: $refresh->expiresAt,
                    accessTokenId: $refresh->accessTokenId,
                    clientId: $refresh->clientId,
                    scopes: $refresh->scopes,
                    attributes: array_replace($refresh->attributes, $reuseAttr),
                    revoked: true,
                    consumed: $refresh->consumed,
                    consumedAt: $refresh->consumedAt,
                    rotatedTo: $refresh->rotatedTo,
                    familyId: $refresh->familyId,
                );
                $this->saveRefreshToken($next);
                $revoked++;
            }
            if ($refresh->accessTokenId instanceof TokenId) {
                $access = $this->findAccessToken($refresh->accessTokenId->value);
                if ($access instanceof OpaqueAccessToken && ! $access->revoked) {
                    if ($this->revokeAccessToken($access->id->value)) {
                        $revoked++;
                    }
                }
            }
            if ($refresh->rotatedTo instanceof TokenId) {
                $nextRefresh = $this->findRefreshToken($refresh->rotatedTo->value);
                if ($nextRefresh instanceof OpaqueRefreshToken) {
                    $queue[] = $nextRefresh;
                }
            }
        }
        return $revoked;
    }

    public function markRotatedTo(string $parentRefreshTokenId, TokenId $nextRefreshId): void
    {
        $existing = $this->findRefreshToken($parentRefreshTokenId);
        if (! $existing instanceof OpaqueRefreshToken) {
            return;
        }
        $next = new OpaqueRefreshToken(
            id: $existing->id,
            reference: $existing->reference,
            issuedAt: $existing->issuedAt,
            expiresAt: $existing->expiresAt,
            accessTokenId: $existing->accessTokenId,
            clientId: $existing->clientId,
            scopes: $existing->scopes,
            attributes: $existing->attributes,
            revoked: $existing->revoked,
            consumed: $existing->consumed,
            consumedAt: $existing->consumedAt,
            rotatedTo: $nextRefreshId,
            familyId: $existing->familyId,
        );
        $this->saveRefreshToken($next);
    }

    private function accessPath(string $tokenId): string
    {
        return $this->accessDirectory . DIRECTORY_SEPARATOR . $this->sanitizeName($tokenId) . '.json';
    }

    private function refreshPath(string $tokenId): string
    {
        return $this->refreshDirectory . DIRECTORY_SEPARATOR . $this->sanitizeName($tokenId) . '.json';
    }

    private function sanitizeName(string $raw): string
    {
        $clean = preg_replace('#[^A-Za-z0-9_\-]#', '_', trim($raw));

        return is_string($clean) && $clean !== '' ? $clean : 'token_' . bin2hex(random_bytes(4));
    }
}
