<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Support;

/**
 * Value object CoseKey — wrapper minimal para interoperar con una clave COSE (EC2 P-256 / RSA RS256).
 *
 * Sirve como punto de intercambio entre:
 *   - la representación COSE_Key almacenada (mapa CBOR numérico kty/alg/crv/x/y/n/e)
 *   - la representación PEM que consume ext-openssl.
 *
 * Esta clase NO hace parsing CBOR; el mapa debe venir ya decodificado (ej: vía CborBuffer).
 *
 * @internal  V2 Passkeys crypto real.
 */
final class CoseKey
{
    /** @var array<int|string, mixed>  mapa COSE ya decodificado */
    public readonly array $map;
    public readonly string $pem;
    public readonly string $algLabel;
    public readonly int $opensslAlg;
    public readonly bool $isEc;

    /**
     * @param array<int|string, mixed> $map
     */
    private function __construct(array $map, string $pem, string $algLabel, int $opensslAlg, bool $isEc)
    {
        $this->map = $map;
        $this->pem = $pem;
        $this->algLabel = $algLabel;
        $this->opensslAlg = $opensslAlg;
        $this->isEc = $isEc;
    }

    /**
     * Construye un CoseKey desde un mapa COSE decodificado.
     *
     * @param array<int|string, mixed> $map
     */
    public static function fromMap(array $map): ?self
    {
        $converter = new CoseKeyToPemConverter();
        $result = $converter->convert($map);
        if ($result === null) {
            return null;
        }
        $kty = isset($map[1]) && is_int($map[1]) ? $map[1] : 0;
        return new self(
            map: $map,
            pem: $result['pem'],
            algLabel: $result['alg'],
            opensslAlg: $result['openssl_algo'],
            isEc: $kty === 2,
        );
    }

    /**
     * Construye un CoseKey DESDE una clave PEM ya cargada (camino inverso: storage PEM → COSE map).
     * Usado cuando el store ya almacena directamente PEMs en lugar de CBOR COSE crudo.
     *
     * @param \OpenSSLAsymmetricKey|resource $publicKey  valor retornado por openssl_pkey_get_public()
     */
    public static function fromOpenSslPublicKey(mixed $publicKey): ?self
    {
        $details = openssl_pkey_get_details($publicKey);
        if ($details === false) {
            return null;
        }
        $type = $details['type'] ?? -1;
        if ($type === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1') {
            $x = $details['ec']['x'] ?? '';
            $y = $details['ec']['y'] ?? '';
            if (! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                return null;
            }
            $map = [
                1 => 2,        // kty = EC2
                3 => -7,       // alg = ES256
                -1 => 1,       // crv = P-256
                -2 => $x,
                -3 => $y,
            ];
            $pem = $details['key'];
            return new self(
                map: $map,
                pem: is_string($pem) ? $pem : '',
                algLabel: 'ES256',
                opensslAlg: OPENSSL_ALGO_SHA256,
                isEc: true,
            );
        }
        if ($type === OPENSSL_KEYTYPE_RSA) {
            $n = $details['rsa']['n'] ?? '';
            $e = $details['rsa']['e'] ?? '';
            if (! is_string($n) || ! is_string($e) || $n === '' || $e === '') {
                return null;
            }
            $map = [
                1 => 3,        // kty = RSA
                3 => -257,     // alg = RS256
                -1 => $n,
                -2 => $e,
            ];
            $pem = $details['key'];
            return new self(
                map: $map,
                pem: is_string($pem) ? $pem : '',
                algLabel: 'RS256',
                opensslAlg: OPENSSL_ALGO_SHA256,
                isEc: false,
            );
        }
        return null;
    }
}
