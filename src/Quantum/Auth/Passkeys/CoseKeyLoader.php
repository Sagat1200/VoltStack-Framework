<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use Quantum\Auth\Passkeys\Exceptions\UnsupportedCoseAlgorithmException;

final class CoseKeyLoader
{
    /** Cose alg IDs: -7 = ES256 (ECDSA P-256 SHA-256), -257 = RS256 (RSA PKCS1v15 SHA-256) */
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /**
     * Formatos aceptados (naive 083):
     *   a) PEM público real (-----BEGIN PUBLIC KEY-----…-----END PUBLIC KEY-----)
     *   b) Wrapper "alg:<alg>;pem:<PEM con saltos línea" (PEM encapsulado manual)
     *   c) Test-only wrapper "alg:<alg>;raw:<arbitrary>" se acepta para tests estructurales SIN openssl;
     *      en este caso la llamada no debe usarse para openssl_verify (detectable porque openssl_key===null).
     *
     * @return array{alg:int, openssl_key: \OpenSSLAsymmetricKey|resource|null, pem:string}
     * @throws UnsupportedCoseAlgorithmException
     */
    public function loadPublicKeyFromRecord(PasskeyCredentialRecord $record): array
    {
        $raw = trim($record->credentialPublicKey);

        if ($raw === '') {
            throw UnsupportedCoseAlgorithmException::forAlg(0);
        }

        if (preg_match('/^alg:(-7|-257);raw:(.*)$/s', $raw, $m)) {
            $alg = (int) $m[1];
            return ['alg' => $alg, 'openssl_key' => null, 'pem' => 'raw:' . $m[2]];
        }

        if (str_starts_with($raw, '-----BEGIN PUBLIC KEY-----')) {
            $key = @openssl_pkey_get_public($raw);
            if ($key === false) {
                return ['alg' => self::ALG_RS256, 'openssl_key' => null, 'pem' => $raw];
            }
            $details = @openssl_pkey_get_details($key);
            $type = $details['type'] ?? OPENSSL_KEYTYPE_RSA;
            $alg = $type === OPENSSL_KEYTYPE_EC ? self::ALG_ES256 : self::ALG_RS256;

            return ['alg' => $alg, 'openssl_key' => $key, 'pem' => $raw];
        }

        if (preg_match('/^alg:(-7|-257);pem:(.+)$/s', $raw, $m)) {
            $alg = (int) $m[1];
            $pem = (string) $m[2];
            if (!in_array($alg, [self::ALG_ES256, self::ALG_RS256], true)) {
                throw UnsupportedCoseAlgorithmException::forAlg($alg);
            }
            $key = @openssl_pkey_get_public($pem);
            if ($key === false) {
                return ['alg' => $alg, 'openssl_key' => null, 'pem' => $pem];
            }
            return ['alg' => $alg, 'openssl_key' => $key, 'pem' => $pem];
        }

        throw UnsupportedCoseAlgorithmException::forAlg(0);
    }
}
