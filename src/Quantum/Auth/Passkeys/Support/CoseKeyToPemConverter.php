<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Support;

/**
 * Parsea una clave pública COSE (del credentialPublicKey de WebAuthn) y la convierte
 * a formato PEM compatible con ext-openssl para verificación de firmas.
 *
 * Soporta algoritmos WebAuthn recomendados:
 *   - ES256 (COSE alg -7): ECDSA P-256 + SHA-256 (kty=2 EC2, crv=1 P-256)
 *   - RS256 (COSE alg -257): RSASSA-PKCS1-v1_5 + SHA-256 (kty=3 RSA, n/e modulus/exponent)
 *
 * @internal V2 Passkeys FIDO2 crypto real.
 */
final class CoseKeyToPemConverter
{
    private const COSE_KTY = 1;
    private const COSE_ALG = 3;
    private const COSE_KTY_EC2 = 2;
    private const COSE_KTY_RSA = 3;
    private const COSE_EC2_CRV = -1;
    private const COSE_EC2_X = -2;
    private const COSE_EC2_Y = -3;
    private const COSE_EC2_CRV_P256 = 1;
    private const COSE_RSA_N = -1;
    private const COSE_RSA_E = -2;
    private const COSE_ALG_ES256 = -7;
    private const COSE_ALG_RS256 = -257;

    /**
     * @param array<int|string, mixed> $coseMap  Mapa COSE decodificado desde CBOR (credentialPublicKey)
     * @return array{pem: string, alg: string, openssl_algo: int, kty: int}|null
     *         Devuelve null si el tipo/algoritmo no es soportado o faltan componentes.
     */
    public function convert(array $coseMap): ?array
    {
        $kty = isset($coseMap[self::COSE_KTY]) && is_int($coseMap[self::COSE_KTY]) ? $coseMap[self::COSE_KTY] : null;
        $alg = isset($coseMap[self::COSE_ALG]) && is_int($coseMap[self::COSE_ALG]) ? $coseMap[self::COSE_ALG] : null;

        if ($kty === self::COSE_KTY_EC2 && $alg === self::COSE_ALG_ES256) {
            return $this->convertEc2Es256($coseMap);
        }
        if ($kty === self::COSE_KTY_RSA && $alg === self::COSE_ALG_RS256) {
            return $this->convertRsaRs256($coseMap);
        }
        return null;
    }

    /**
     * @param array<int|string, mixed> $map
     * @return array{pem: string, alg: string, openssl_algo: int, kty: int}|null
     */
    private function convertEc2Es256(array $map): ?array
    {
        $crv = isset($map[self::COSE_EC2_CRV]) && is_int($map[self::COSE_EC2_CRV]) ? $map[self::COSE_EC2_CRV] : null;
        $x = $map[self::COSE_EC2_X] ?? null;
        $y = $map[self::COSE_EC2_Y] ?? null;
        if ($crv !== self::COSE_EC2_CRV_P256 || ! is_string($x) || ! is_string($y)) {
            return null;
        }
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        $keyBin = "\x04" . $x . $y;
        $oidHex = '2a8648ce3d030107'; // OID prime256v1 / secp256r1
        $der = $this->encodeDerSubjectPublicKeyInfo($oidHex, $keyBin);
        $pem = $this->derToPem($der, 'PUBLIC KEY');
        return [
            'pem' => $pem,
            'alg' => 'ES256',
            'openssl_algo' => OPENSSL_ALGO_SHA256,
            'kty' => self::COSE_KTY_EC2,
        ];
    }

    /**
     * @param array<int|string, mixed> $map
     * @return array{pem: string, alg: string, openssl_algo: int, kty: int}|null
     */
    private function convertRsaRs256(array $map): ?array
    {
        $n = $map[self::COSE_RSA_N] ?? null;
        $e = $map[self::COSE_RSA_E] ?? null;
        if (! is_string($n) || ! is_string($e) || $n === '' || $e === '') {
            return null;
        }
        $modulus = $this->ensureUnsignedInteger($n);
        $exponent = $this->ensureUnsignedInteger($e);

        $nInt = $this->encodeDerInteger($modulus);
        $eInt = $this->encodeDerInteger($exponent);
        $seq = $this->encodeDerSequence($nInt . $eInt);
        $oidHex = '2a864886f70d010101'; // rsaEncryption OID
        $null = "\x05\x00";
        $algId = $this->encodeDerSequence(hex2bin($oidHex) . $null);
        $bitString = $this->encodeDerBitString($seq);
        $spki = $this->encodeDerSequence($algId . $bitString);
        $pem = $this->derToPem($spki, 'PUBLIC KEY');
        return [
            'pem' => $pem,
            'alg' => 'RS256',
            'openssl_algo' => OPENSSL_ALGO_SHA256,
            'kty' => self::COSE_KTY_RSA,
        ];
    }

    private function ensureUnsignedInteger(string $bytes): string
    {
        $len = strlen($bytes);
        if ($len > 0 && (ord($bytes[0]) & 0x80) !== 0) {
            return "\x00" . $bytes;
        }
        return $bytes;
    }

    private function encodeDerSubjectPublicKeyInfo(string $oidHex, string $keyBits): string
    {
        $algOidBytes = hex2bin($oidHex);
        if ($algOidBytes === false) {
            $algOidBytes = '';
        }
        $algOidDer = $this->encodeDerOidRaw($algOidBytes);
        $null = "\x05\x00";
        $algId = $this->encodeDerSequence($algOidDer . $null);
        $bitString = $this->encodeDerBitString($keyBits);
        return $this->encodeDerSequence($algId . $bitString);
    }

    private function encodeDerOidRaw(string $oidBytes): string
    {
        $len = strlen($oidBytes);
        if ($len < 0x80) {
            return "\x06" . chr($len) . $oidBytes;
        }
        $lenBytes = $this->encodeLength($len);
        return "\x06" . $lenBytes . $oidBytes;
    }

    private function encodeDerBitString(string $data): string
    {
        $padded = "\x00" . $data;
        return "\x03" . $this->encodeLength(strlen($padded)) . $padded;
    }

    private function encodeDerInteger(string $unsignedBytes): string
    {
        return "\x02" . $this->encodeLength(strlen($unsignedBytes)) . $unsignedBytes;
    }

    private function encodeDerSequence(string $inner): string
    {
        return "\x30" . $this->encodeLength(strlen($inner)) . $inner;
    }

    private function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        $v = $len;
        while ($v > 0) {
            $bytes = chr($v & 0xFF) . $bytes;
            $v >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function derToPem(string $der, string $label): string
    {
        $base64 = base64_encode($der);
        $wrapped = chunk_split($base64, 64, "\n");
        return "-----BEGIN {$label}-----\n" . $wrapped . "-----END {$label}-----\n";
    }
}
