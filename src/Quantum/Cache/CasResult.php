<?php

declare(strict_types=1);

namespace Quantum\Cache;

/**
 * Result of a compare-and-swap operation. CAS conflicts produce Rejected,
 * not connectivity errors. ACK loss produces Unknown.
 */
final readonly class CasResult
{
    public const APPLIED = 'applied';
    public const REJECTED = 'rejected';
    public const UNKNOWN = 'unknown';

    /**
     * @param string $state         one of applied|rejected|unknown
     * @param string $key
     * @param mixed  $value         the value that is now stored (when applied)
     * @param string $token         the new token (when applied) or the current token (when rejected)
     * @param string $reason        machine-readable reason for rejection/unknown
     * @param int|null $incarnation current incarnation after the operation
     */
    public function __construct(
        public string $state,
        public string $key,
        public mixed $value,
        public string $token,
        public string $reason = '',
        public ?int $incarnation = null,
    ) {
    }

    public function isApplied(): bool
    {
        return $this->state === self::APPLIED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    public function isUnknown(): bool
    {
        return $this->state === self::UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'key' => $this->key,
            'value' => $this->value,
            'token' => $this->token,
            'reason' => $this->reason,
            'incarnation' => $this->incarnation,
        ];
    }
}
