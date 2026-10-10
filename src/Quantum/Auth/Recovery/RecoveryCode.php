<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Value Object inmutable que representa un recovery code emitido para una identidad.
 *
 * - `code`: código legible/plain (solo presente en la emisión; el storage durable
 *   debe almacenar `codeHash` únicamente; al consumir compara hash).
 * - `codeHash`: hash persistente del código (recomendado: `password_hash` o `hash('sha256')`
 *   con pepper conocido; este módulo acepta cualquier cadena y delega la decisión de
 *   al algoritmo al componente que emite códigos).
 * - `identityIdentifier`: identificador de la identidad a la que el código está ligado
 *   (formato compatible con `IdentityProviderInterface::findByIdentifier()`).
 * - `identityType`: tipo de identidad (user/client/device).
 * - `issuedAt` / `expiresAt`: timestamps Unix; `expiresAt === null` implica no expira
 *   por tiempo (solo one-time).
 * - `consumedAt`: cuando `!== null` el código ya no es reutilizable.
 * - `metadata[]`: payload opaco; puede contener `emission_reference`, `source`,
 *   `requires_rotation_after_use`, etc.
 */
final readonly class RecoveryCode
{
    public function __construct(
        public string $codeHash,
        public string $identityIdentifier,
        public string $identityType,
        public int $issuedAt,
        public ?int $expiresAt = null,
        public ?int $consumedAt = null,
        public array $metadata = [],
        public ?string $code = null,
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        $now ??= time();

        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isUsable(?int $now = null): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired($now);
    }

    public function withConsumedAt(int $consumedAt): self
    {
        return new self(
            $this->codeHash,
            $this->identityIdentifier,
            $this->identityType,
            $this->issuedAt,
            $this->expiresAt,
            $consumedAt,
            $this->metadata,
            null,
        );
    }

    public function toArray(): array
    {
        return [
            'code_hash' => $this->codeHash,
            'identity_identifier' => $this->identityIdentifier,
            'identity_type' => $this->identityType,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'consumed_at' => $this->consumedAt,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['code_hash'] ?? ''),
            (string) ($row['identity_identifier'] ?? ''),
            (string) ($row['identity_type'] ?? 'user'),
            (int) ($row['issued_at'] ?? time()),
            isset($row['expires_at']) ? (int) $row['expires_at'] : null,
            isset($row['consumed_at']) ? (int) $row['consumed_at'] : null,
            isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [],
        );
    }
}
