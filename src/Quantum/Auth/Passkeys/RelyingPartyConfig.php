<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

/**
 * @internal skeleton V1 — no-op criptografía para 082
 */
final readonly class RelyingPartyConfig
{
    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public string $rpId,
        public string $rpName,
        public array $allowedOrigins = [],
    ) {
    }

    /**
     * @param array{rp_id?: string, rp_name?: string, origins?: list<string>} $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            rpId: is_string($input['rp_id'] ?? null) && trim((string) $input['rp_id']) !== ''
                ? trim((string) $input['rp_id'])
                : 'localhost',
            rpName: is_string($input['rp_name'] ?? null) && trim((string) $input['rp_name']) !== ''
                ? trim((string) $input['rp_name'])
                : 'VoltStack Local',
            allowedOrigins: array_values(array_filter(
                $input['origins'] ?? [],
                static fn (mixed $o): bool => is_string($o) && trim($o) !== '',
            )),
        );
    }
}
