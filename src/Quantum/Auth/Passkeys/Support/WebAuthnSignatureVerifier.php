<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Support;

/**
 * Verificador de firmas WebAuthn para Passkeys FIDO2 (V2 crypto real).
 *
 * Assertion flow (sign-in):
 *   signature = sign( SHA-256(authenticatorData || SHA-256(clientDataJSON)) , privateKey )
 *   verificamos: openssl_verify( signedMessage , signature , pemPubKey , OPENSSL_ALGO_SHA256 ) === 1
 *
 * Attestation flow (registration, packed basic):
 *   attStmt.sig = sign( authData || SHA-256(clientDataHash) , attestedCredKey )
 *   misma verificación usando la clave atestada recién creada.
 *
 * @internal V2 crypto real Passkeys FIDO2.
 */
final class WebAuthnSignatureVerifier
{
    private CoseKeyToPemConverter $coseConverter;

    public function __construct()
    {
        $this->coseConverter = new CoseKeyToPemConverter();
    }

    /**
     * Verifica firma de assertion (ceremonia de inicio de sesión).
     *
     * @param array<int|string, mixed> $credentialCoseMap  Mapa COSE de la clave pública almacenada (credential_public_key CBOR decoded)
     * @param string $authenticatorData  Bytes crudos authenticatorData
     * @param string $clientDataJson     clientDataJSON crudo (desde navegador, antes de base64url encode o después de decodificar)
     * @param string $signature          Firma cruda desde authenticator
     * @return array{valid: bool, alg?: string, openssl_algo?: int, error?: string}
     */
    public function verifyAssertion(
        array $credentialCoseMap,
        string $authenticatorData,
        string $clientDataJson,
        string $signature,
    ): array {
        $pub = $this->coseConverter->convert($credentialCoseMap);
        if ($pub === null) {
            return ['valid' => false, 'error' => 'unsupported_cose_key'];
        }
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signed = $authenticatorData . $clientDataHash;

        $key = openssl_pkey_get_public($pub['pem']);
        if ($key === false) {
            return ['valid' => false, 'error' => 'invalid_pem_public_key'];
        }
        $result = openssl_verify($signed, $signature, $key, $pub['openssl_algo']);
        if (is_resource($key) || $key instanceof \OpenSSLAsymmetricKey) {
            @openssl_free_key($key);
        }
        if ($result === 1) {
            return [
                'valid' => true,
                'alg' => $pub['alg'],
                'openssl_algo' => $pub['openssl_algo'],
            ];
        }
        return [
            'valid' => false,
            'error' => $result === 0 ? 'signature_mismatch' : 'openssl_error_' . openssl_error_string(),
        ];
    }

    /**
     * Verificación de attestation simplificada para packed format (V2 inicial).
     * Solo validamos estructura y que la firma sea verificable contra la clave atestada.
     * Para una implementación más estricta (con cadena de confianza x5c), este es el punto de extensión.
     *
     * @param array<int|string, mixed> $attestedCredentialCose  CBOR decoded del authData->attestedCredentialData->credentialPublicKey
     * @param string $authenticatorData          Bytes crudos authenticatorData
     * @param string $clientDataJson             clientDataJSON crudo
     * @param string $attestationSignature       Firma attestation (attStmt.sig)
     * @return array{valid: bool, alg?: string, error?: string}
     */
    public function verifyPackedAttestation(
        array $attestedCredentialCose,
        string $authenticatorData,
        string $clientDataJson,
        string $attestationSignature,
    ): array {
        return $this->verifyAssertion(
            $attestedCredentialCose,
            $authenticatorData,
            $clientDataJson,
            $attestationSignature,
        );
    }
}
