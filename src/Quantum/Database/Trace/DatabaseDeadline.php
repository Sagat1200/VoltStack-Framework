<?php declare(strict_types=1);

namespace Quantum\Database\Trace;

/**
 * Deadline cooperativo monotónico. Usa hrtime() para evitar saltos por NTP.
 */
final readonly class DatabaseDeadline
{
    public function __construct(
        public float $expiresAtHrTime,
    ) {}

    public static function fromMs(int $timeoutMs): self
    {
        return new self(hrtime(true) + ($timeoutMs * 1_000_000));
    }

    public function isExpired(): bool { return hrtime(true) >= $this->expiresAtHrTime; }

    /** @return int<0,max> ms restantes; 0 si ya expiró */
    public function remainingMs(): int
    {
        $left = (int)(($this->expiresAtHrTime - hrtime(true)) / 1_000_000);
        return max(0, $left);
    }
}
