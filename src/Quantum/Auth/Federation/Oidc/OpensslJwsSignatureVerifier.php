<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;

final class OpensslJwsSignatureVerifier implements OidcSignatureVerifierInterface
{
    public function __construct(
        private readonly JoseSimpleParser $parser = new JoseSimpleParser(),
    ) {
    }

    public function verifyIdTokenSignature(string $compactJws, OidcJwksCacheInterface $jwksCache, ?string $overriddenKid = null): array
    {
        $parsed = $this->parser->parse($compactJws);
        if ($parsed === null) {
            return ['valid' => false, 'alg' => null, 'matched_kid' => null, 'error' => 'jws_malformed'];
        }
        $header = $parsed['header'];
        $alg = is_string($header['alg'] ?? null) ? $header['alg'] : null;
        $kid = is_string($overriddenKid) && $overriddenKid !== '' ? $overriddenKid : $this->parser->kidOf($header);
        if ($kid === null) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => null, 'error' => 'kid_missing'];
        }
        if ($alg !== 'RS256' && $alg !== 'ES256') {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'alg_unsupported'];
        }
        $jwk = $jwksCache->getKey($kid);
        if (! is_array($jwk)) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'jwk_missing'];
        }
        $kty = (string) ($jwk['kty'] ?? '');
        if (($alg === 'RS256' && $kty !== 'RSA') || ($alg === 'ES256' && $kty !== 'EC')) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'kty_alg_mismatch'];
        }
        $pem = $this->jwkToPem($jwk);
        if ($pem === null) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'jwk_pem_encode_failed'];
        }
        $publicKey = @openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'openssl_pubkey_load_failed'];
        }
        $sigRaw = $parsed['signature_raw'];
        if ($sigRaw === null) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'sig_decode_failed'];
        }
        if ($alg === 'ES256') {
            $sig = self::ecRaw64ToDer($sigRaw);
            if ($sig === null) {
                return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'ec_sig_der_encode_failed'];
            }
        } else {
            $sig = $sigRaw;
        }
        $message = $parsed['signing_input'];
        $rc = openssl_verify($message, $sig, $publicKey, OPENSSL_ALGO_SHA256);
        if ($publicKey instanceof \OpenSSLAsymmetricKey) {
            openssl_free_key($publicKey);
        }
        if ($rc === -1 || $rc === false) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'openssl_verify_error_' . intval($rc)];
        }
        return [
            'valid' => $rc === 1,
            'alg' => $alg,
            'matched_kid' => $kid,
            'error' => $rc === 1 ? null : 'signature_invalid',
        ];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    public function jwkToPem(array $jwk): ?string
    {
        $kty = (string) ($jwk['kty'] ?? '');
        return match ($kty) {
            'RSA' => self::jwkRsaToPem($jwk),
            'EC' => self::jwkEcP256ToPem($jwk),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private static function jwkRsaToPem(array $jwk): ?string
    {
        $nRaw = self::b64uDec((string) ($jwk['n'] ?? ''));
        $eRaw = self::b64uDec((string) ($jwk['e'] ?? ''));
        if ($nRaw === null || $eRaw === null || $nRaw === '' || $eRaw === '') {
            return null;
        }
        $nInt = self::derIntegerPositive($nRaw);
        $eInt = self::derIntegerPositive($eRaw);
        $rsaPubkeySeq = self::derSequence($nInt . $eInt);
        $bitString = self::derBitString($rsaPubkeySeq);
        $rsaOid = self::derOid('1.2.840.113549.1.1.1') . "\x05\x00";
        $algorithmSeq = self::derSequence($rsaOid);
        $spki = self::derSequence($algorithmSeq . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private static function jwkEcP256ToPem(array $jwk): ?string
    {
        $crv = (string) ($jwk['crv'] ?? '');
        if ($crv !== 'P-256' && $crv !== '1') {
            return null;
        }
        $xRaw = self::b64uDec((string) ($jwk['x'] ?? ''));
        $yRaw = self::b64uDec((string) ($jwk['y'] ?? ''));
        if ($xRaw === null || $yRaw === null || strlen($xRaw) !== 32 || strlen($yRaw) !== 32) {
            return null;
        }
        $pubPoint = "\x04" . $xRaw . $yRaw;
        $bitString = self::derBitString($pubPoint);
        $ecOid = self::derOid('1.2.840.10045.2.1');
        $p256Oid = self::derOid('1.2.840.10045.3.1.7');
        $algorithmSeq = self::derSequence($ecOid . $p256Oid);
        $spki = self::derSequence($algorithmSeq . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function ecRaw64ToDer(string $raw64): ?string
    {
        if (strlen($raw64) !== 64) {
            return null;
        }
        $rBin = substr($raw64, 0, 32);
        $sBin = substr($raw64, 32, 32);
        return self::derSequence(self::derIntegerPositive($rBin) . self::derIntegerPositive($sBin));
    }

    private static function b64uDec(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $urlDecoded = strtr($data, '-_', '+/');
        $raw = base64_decode($urlDecoded, true);
        return $raw === false ? null : $raw;
    }

    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    private static function derBitString(string $contents): string
    {
        $inner = "\x00" . $contents;
        return "\x03" . self::derLength(strlen($inner)) . $inner;
    }

    private static function derIntegerPositive(string $unsignedBytes): string
    {
        $value = ltrim($unsignedBytes, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::derLength(strlen($value)) . $value;
    }

    private static function derOid(string $dotted): string
    {
        $parts = array_map('intval', explode('.', $dotted));
        $out = chr(40 * $parts[0] + $parts[1]);
        $len = count($parts);
        for ($i = 2; $i < $len; $i++) {
            $value = $parts[$i];
            if ($value < 128) {
                $out .= chr($value);
            } else {
                $rev = [];
                $rev[] = $value & 0x7F;
                $value >>= 7;
                while ($value > 0) {
                    $rev[] = ($value & 0x7F) | 0x80;
                    $value >>= 7;
                }
                for ($j = count($rev) - 1; $j >= 0; $j--) {
                    $out .= chr($rev[$j]);
                }
            }
        }
        return "\x06" . self::derLength(strlen($out)) . $out;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $lenBytes = '';
        $tmp = $length;
        while ($tmp > 0) {
            $lenBytes = chr($tmp & 0xFF) . $lenBytes;
            $tmp >>= 8;
        }
        return chr(0x80 | strlen($lenBytes)) . $lenBytes;
    }
}
