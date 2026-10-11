<?php

declare(strict_types=1);

namespace Quantum\Cache;

/**
 * Opaque snapshot returned by CAS-capable stores. The token is a version
 * identifier that includes an incarnation counter to defeat the ABA problem
 * (a value written, deleted and re-created with the same content must not
 * compare equal to the previous snapshot).
 */
final readonly class CasSnapshot
{
    /**
     * @param string $key
     * @param mixed  $value
     * @param string $token        opaque version token (includes incarnation)
     * @param int|null $expiresAt  unix timestamp or null for forever
     * @param int $incarnation    monotonic counter bumped on every mutation
     */
    public function __construct(
        public string $key,
        public mixed $value,
        public string $token,
        public ?int $expiresAt,
        public int $incarnation,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'token' => $this->token,
            'expires_at' => $this->expiresAt,
            'incarnation' => $this->incarnation,
        ];
    }
}
