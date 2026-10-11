<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\FederatedIdentityLinkProviderInterface;
use Quantum\Auth\Contracts\FederatedLinkNonceStoreInterface;
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
 *       - `nonce`    (opcional): nonce emitido con el recovery token.
 *
 *   (b) Cadena `id_token_hint` (no firmado en modo ligero): se extrae el payload
 *       JWT base64url y se leen `iss`/`aud` → `rp_id`, `sub` → subject y `nonce`.
 *
 * En ambos casos, la comprobación final se delega a
 * `FederatedIdentityLinkProviderInterface::isLinkedTo()` que valida la tupla
 * (identity_reference, rp_id, sub) contra el almacén de links federados.
 *
 * ## Nonce binding (replay protection)
 *
 * Si el recovery token tiene un `nonce` asociado (emitido en begin()), el JWT
 * de evidencia DEBE contener el mismo nonce en su claim `nonce`. La comparación
 * se realiza con `hash_equals()` para evitar timing attacks. Si el nonce no
 * coincide, la evidencia falla.
 *
 * Adicionalmente, si hay un `FederatedLinkNonceStoreInterface` configurado,
 * el nonce se consume de forma one-time: un segundo intento con el mismo nonce
 * (incluso en un token distinto) será rechazado.
 *
 * Backward compat: tokens emitidos antes de esta funcionalidad no tienen nonce;
 * en ese caso se omite la verificación de nonce.
 *
 * Si `FederatedIdentityLinkProviderInterface` no puede resolver links (fallback
 * `skip=true`) para dar paso a otros verifiers custom downstream.
 */
final class FederatedLinkRecoveryEvidenceVerifier implements RecoveryEvidenceVerifierInterface
{
    public function __construct(
        private readonly ?FederatedIdentityLinkProviderInterface $linkProvider = null,
        private readonly ?FederatedLinkNonceStoreInterface $nonceStore = null,
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

        ['rp_id' => $rpId, 'sub' => $sub, 'nonce' => $evidenceNonce] = $this->parsePair($evidence->value, $evidence->metadata);
        $rpId = trim((string) $rpId);
        $sub = trim((string) $sub);
        $evidenceNonce = is_string($evidenceNonce) ? trim($evidenceNonce) : '';

        if ($rpId === '' || $sub === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.federated_link.missing_pair',
            );
        }

        // Verificación de nonce binding (replay protection).
        $tokenNonce = $token->nonce();
        if ($tokenNonce !== null) {
            if ($evidenceNonce === '' || ! hash_equals($tokenNonce, $evidenceNonce)) {
                return RecoveryEvidenceVerificationResult::failed(
                    $evidence->kind,
                    'recovery.evidence.federated_link.nonce_mismatch',
                    [
                        'rp_id' => $rpId,
                        'nonce_present' => $evidenceNonce !== '',
                    ],
                );
            }

            // Consumir el nonce one-time para evitar replay.
            if ($this->nonceStore instanceof FederatedLinkNonceStoreInterface) {
                $consumed = $this->nonceStore->consume($tokenNonce);
                if (! $consumed) {
                    return RecoveryEvidenceVerificationResult::failed(
                        $evidence->kind,
                        'recovery.evidence.federated_link.nonce_replayed',
                        ['rp_id' => $rpId],
                    );
                }
            }
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
                'nonce_bound' => $tokenNonce !== null,
            ],
        );
    }

    /**
     * @param scalar|array|object $value
     * @param array               $metadata
     *
     * @return array{rp_id: string, sub: string, nonce: string}
     */
    private function parsePair(mixed $value, array $metadata): array
    {
        $rpId = '';
        $sub = '';
        $nonce = '';

        $data = $this->toAssoc($value);
        if ($data !== []) {
            $rpId = (string) ($data['rp_id'] ?? $data['iss'] ?? $data['aud'] ?? '');
            $sub = (string) ($data['sub'] ?? $data['subject'] ?? '');
            $nonce = (string) ($data['nonce'] ?? '');
        }

        if (($rpId === '' || $sub === '') && is_string($value) && $value !== '') {
            $fromJwt = $this->parseJwtPayload($value);
            if ($fromJwt !== []) {
                $rpId = (string) ($fromJwt['iss'] ?? $fromJwt['aud'] ?? $rpId);
                $sub = (string) ($fromJwt['sub'] ?? $sub);
                $nonce = (string) ($fromJwt['nonce'] ?? $nonce);
            }
        }

        if ($rpId === '') {
            $rpId = (string) ($metadata['rp_id'] ?? $metadata['issuer'] ?? '');
        }
        if ($sub === '') {
            $sub = (string) ($metadata['sub'] ?? $metadata['subject'] ?? '');
        }
        if ($nonce === '') {
            $nonce = (string) ($metadata['nonce'] ?? '');
        }

        return ['rp_id' => $rpId, 'sub' => $sub, 'nonce' => $nonce];
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
        // link provider + nonce binding para replay. Validación firma queda para
        // paths de seguridad de entrada.
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
