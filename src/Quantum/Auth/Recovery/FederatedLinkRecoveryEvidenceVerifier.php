<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\FederatedIdentityLinkProviderInterface;
use Quantum\Auth\Contracts\RecoveryEvidenceVerifierInterface;
use Quantum\Auth\Identity\IdentityReference;

/**
 * Verifies evidence of kind `RecoveryEvidenceKind::FederatedLink`.
 *
 * Dos formatos de `$evidence->value`:
 *
 *   (a) Arreglo asociativo / JSON-parseado con:
 *       - `rp_id`    (requerido): ID textual del proveedor federado (issuer o alias).
 *       - `sub`      (requerido): subject persistente en ese RP.
 *
 *   (b) Cadena `id_token_hint` (no firmado en modo ligero): se extrae el payload
 *       JWT base64url y se leen `iss`/`aud` → `rp_id` y `sub` → subject.
 *
 * En ambos casos, la comprobación final se delega a
 * `FederatedIdentityLinkProviderInterface::isLinkedTo()` que valida la tupla
 * (identity_reference, rp_id, sub) contra el almacén de links federados.
 *
 * Si `FederatedIdentityLinkProviderInterface` no puede resolver links (fallback
 * `skip=true`) para dar paso a otros verifiers custom downstream.
 */
final class FederatedLinkRecoveryEvidenceVerifier implements RecoveryEvidenceVerifierInterface
{
    public function __construct(
        private readonly ?FederatedIdentityLinkProviderInterface $linkProvider = null,
    ) {
    }

    public function verify(
        RecoveryEvidence $evidence,
        IdentityReference $identity,
        PasswordResetTokenRecord $token,
    ): RecoveryEvidenceVerificationResult {
        if ($evidence->kind !== RecoveryEvidenceKind::FederatedLink) {
            return RecoveryEvidenceVerificationResult::skip($evidence->kind);
        }
        if ($this->linkProvider === null) {
            return RecoveryEvidenceVerificationResult::skip(
                $evidence->kind,
                'recovery.evidence.federated_link.no_link_provider',
            );
        }

        ['rp_id' => $rpId, 'sub' => $sub] = $this->parsePair($evidence->value, $evidence->metadata);
        $rpId = trim((string) $rpId);
        $sub = trim((string) $sub);
        if ($rpId === '' || $sub === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.federated_link.missing_pair',
            );
        }

        $linked = $this->linkProvider->isLinkedTo($identity, $rpId, $sub);
        if (! $linked) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.federated_link.not_linked',
                [
                    'rp_id' => $rpId,
                    'sub_present' => true,
                ],
            );
        }

        return RecoveryEvidenceVerificationResult::passed(
            $evidence->kind,
            'recovery.evidence.federated_link.linked',
            [
                'rp_id' => $rpId,
            ],
        );
    }

    /**
     * @param scalar|array|object $value
     * @param array               $metadata
     *
     * @return array{rp_id: string, sub: string}
     */
    private function parsePair(mixed $value, array $metadata): array
    {
        $rpId = '';
        $sub = '';

        $data = $this->toAssoc($value);
        if ($data !== []) {
            $rpId = (string) ($data['rp_id'] ?? $data['iss'] ?? $data['aud'] ?? '');
            $sub = (string) ($data['sub'] ?? $data['subject'] ?? '');
        }

        if (($rpId === '' || $sub === '') && is_string($value) && $value !== '') {
            $fromJwt = $this->parseJwtPayload($value);
            if ($fromJwt !== []) {
                $rpId = (string) ($fromJwt['iss'] ?? $fromJwt['aud'] ?? $rpId);
                $sub = (string) ($fromJwt['sub'] ?? $sub);
            }
        }

        if ($rpId === '') {
            $rpId = (string) ($metadata['rp_id'] ?? $metadata['issuer'] ?? '');
        }
        if ($sub === '') {
            $sub = (string) ($metadata['sub'] ?? $metadata['subject'] ?? '');
        }

        return ['rp_id' => $rpId, 'sub' => $sub];
    }

    /**
     * @return array<string, mixed>
     */
    private function toAssoc(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = $v;
            }

            return $out;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (is_string($value) && trim($value) !== '' && ($value[0] === '{' || $value[0] === '[')) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJwtPayload(string $jwtLike): array
    {
        // Modo ligero: NO valida firma. Se apoya en el proof que el recovery token
        // fue emitido con identifier conocido + que el RP binding se confirma en el
        // link provider. Validación firma queda para paths de seguridad de entrada.
        $parts = explode('.', $jwtLike);
        if (count($parts) < 2) {
            return [];
        }
        $payload = $parts[1];
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false || trim($decoded) === '') {
            return [];
        }
        try {
            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($json) ? $json : [];
    }
}
