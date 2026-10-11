<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

/**
 * Shape estable de un grant de delegacion.
 *
 * Un grant delegado concede role/permission al trustee SOLAMENTE cuando
 * el trustee actua en nombre del grantor (modo impersonation) dentro
 * del scope indicado. Fuera de ese contexto, el grant no tiene efecto
 * (fail-closed / default-deny).
 */
final readonly class DelegationGrant implements \JsonSerializable
{
    public function __construct(
        public string $trusteeId,
        public string $grantorId,
        public string $scope,
        public string $type,
        public string $value,
        public ?string $grantedAt = null,
        public ?string $expiresAt = null,
    ) {}

    /**
     * @return array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string,granted_at:string|null,expires_at:string|null}
     */
    public function toArray(): array
    {
        return [
            'trustee_id' => $this->trusteeId,
            'grantor_id' => $this->grantorId,
            'scope' => $this->scope,
            'type' => $this->type,
            'value' => $this->value,
            'granted_at' => $this->grantedAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $expires = new \DateTimeImmutable($this->expiresAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        return $expires <= $now;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
