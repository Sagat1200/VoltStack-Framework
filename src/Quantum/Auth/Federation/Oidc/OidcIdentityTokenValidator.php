<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;

/**
 * @internal skeleton V2 para 083 con validación criptográfica real de JOSE RS256/ES256
 *           sobre JWKS via openssl_verify PHP nativo (no Composer).
 *           El método validateSignaturePresent() preserva backward compat como
 *           chequeo structural presence kid; validateSignature() sí valida la firma.
 *           083-P1: Inject nullable OidcSignatureVerifierInterface (compat 082 null = keep internal openssl path; non-null = use interface).
 */
final class OidcIdentityTokenValidator
{
    public function __construct(
        private readonly ?OidcSignatureVerifierInterface $signatureVerifier = null,
    ) {
    }
    public function validateIssuer(array $tokenClaims, string $expectedIssuer): bool
    {
        $iss = $tokenClaims['iss'] ?? null;
        return is_string($iss) && rtrim($iss, '/') === rtrim($expectedIssuer, '/');
    }

    public function validateAudience(array $tokenClaims, mixed $expectedAud): bool
    {
        $aud = $tokenClaims['aud'] ?? null;
        $audList = is_array($aud) ? array_values($aud) : (is_string($aud) ? [$aud] : []);
        $expectedList = is_array($expectedAud) ? array_values($expectedAud) : (is_string($expectedAud) ? [$expectedAud] : []);

        foreach ($expectedList as $e) {
            if (! is_string($e)) {
                continue;
            }
            if (in_array($e, $audList, true)) {
                return true;
            }
        }
        return false;
    }

    public function validateExpiry(array $tokenClaims, int $nowTs, int $clockSkewLeewaySec = 0): bool
    {
        $exp = $tokenClaims['exp'] ?? null;
        if (! is_int($exp)) {
            return false;
        }
        $nbf = $tokenClaims['nbf'] ?? null;
        if (is_int($nbf) && $nowTs + $clockSkewLeewaySec < $nbf) {
            return false;
        }
        return ($exp + $clockSkewLeewaySec) > $nowTs;
    }

    public function validateNonce(array $tokenClaims, string $expectedNonce): bool
    {
        $nonce = $tokenClaims['nonce'] ?? null;
        return is_string($nonce) && hash_equals($expectedNonce, $nonce);
    }

    public function validateSignaturePresent(array $tokenHeader, OidcJwksCacheInterface $cache, ?string $kid = null): bool
    {
        $resolvedKid = is_string($kid) && trim($kid) !== '' ? $kid : ($tokenHeader['kid'] ?? null);
        if (! is_string($resolvedKid) || trim($resolvedKid) === '') {
            return false;
        }
        return $cache->getKey($resolvedKid) !== null;
    }

    public function splitCompactJws(string $compactJws): ?array
    {
        $parts = explode('.', $compactJws);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64u, $payloadB64u, $sigB64u] = $parts;
        if ($headerB64u === '' || $payloadB64u === '' || $sigB64u === '') {
            return null;
        }
        return [
            'header_b64u' => $headerB64u,
            'payload_b64u' => $payloadB64u,
            'signature_b64u' => $sigB64u,
            'signing_input' => $headerB64u . '.' . $payloadB64u,
        ];
    }

    private static function b64uDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $urlDecoded = strtr($data, '-_', '+/');
        $raw = base64_decode($urlDecoded, true);
        return $raw === false ? null : $raw;
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function decodeCompactJwsHeaderAndPayload(string $compactJws): ?array
    {
        $split = $this->splitCompactJws($compactJws);
        if ($split === null) {
            return null;
        }
        $headerRaw = self::b64uDecode($split['header_b64u']);
        $payloadRaw = self::b64uDecode($split['payload_b64u']);
        if ($headerRaw === null || $payloadRaw === null) {
            return null;
        }
        $header = json_decode($headerRaw, true);
        $payload = json_decode($payloadRaw, true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }
        return [
            'header' => $header,
            'payload' => $payload,
            'signing_input' => $split['signing_input'],
            'signature_raw' => self::b64uDecode($split['signature_b64u']),
        ];
    }

    private static function jwkRsaToPem(array $jwk): ?string
    {
        $nRaw = self::b64uDecode((string)($jwk['n'] ?? ''));
        $eRaw = self::b64uDecode((string)($jwk['e'] ?? ''));
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

    private static function jwkEcP256ToPem(array $jwk): ?string
    {
        $crv = (string)($jwk['crv'] ?? '');
        if ($crv !== 'P-256' && $crv !== '1') {
            return null;
        }
        $xRaw = self::b64uDecode((string)($jwk['x'] ?? ''));
        $yRaw = self::b64uDecode((string)($jwk['y'] ?? ''));
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

    public function jwkToPemPublicKey(array $jwk): ?string
    {
        $kty = (string)($jwk['kty'] ?? '');
        return match ($kty) {
            'RSA' => self::jwkRsaToPem($jwk),
            'EC' => self::jwkEcP256ToPem($jwk),
            default => null,
        };
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

    public function validateSignature(string $compactJws, OidcJwksCacheInterface $cache, ?string $overriddenKid = null): array
    {
        if ($this->signatureVerifier !== null) {
            return $this->signatureVerifier->verifyIdTokenSignature($compactJws, $cache, $overriddenKid);
        }

        $decoded = $this->decodeCompactJwsHeaderAndPayload($compactJws);
        if ($decoded === null) {
            return ['valid' => false, 'alg' => null, 'matched_kid' => null, 'error' => 'jws_malformed'];
        }
        $header = $decoded['header'];
        $alg = is_string($header['alg'] ?? null) ? $header['alg'] : null;
        $kid = is_string($overriddenKid) && $overriddenKid !== '' ? $overriddenKid : ($header['kid'] ?? null);
        if (! is_string($kid) || $kid === '') {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => null, 'error' => 'kid_missing'];
        }
        if ($alg !== 'RS256' && $alg !== 'ES256') {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'alg_unsupported'];
        }
        $jwk = $cache->getKey($kid);
        if (! is_array($jwk)) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'jwk_missing'];
        }
        $kty = (string)($jwk['kty'] ?? '');
        if (($alg === 'RS256' && $kty !== 'RSA') || ($alg === 'ES256' && $kty !== 'EC')) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'kty_alg_mismatch'];
        }
        $pem = $this->jwkToPemPublicKey($jwk);
        if ($pem === null) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'jwk_pem_encode_failed'];
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return ['valid' => false, 'alg' => $alg, 'matched_kid' => $kid, 'error' => 'openssl_pubkey_load_failed'];
        }
        $sigRaw = $decoded['signature_raw'];
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
        $message = $decoded['signing_input'];
        $rc = openssl_verify($message, $sig, $publicKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKey);
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

    public function validateAll(array $tokenClaims, array $expectations = []): array
    {
        $reasons = [];

        if (isset($expectations['issuer']) && is_string($expectations['issuer'])) {
            if (! $this->validateIssuer($tokenClaims, $expectations['issuer'])) {
                $reasons[] = 'issuer_mismatch';
            }
        }
        if (array_key_exists('audience', $expectations)) {
            if (! $this->validateAudience($tokenClaims, $expectations['audience'])) {
                $reasons[] = 'audience_mismatch';
            }
        }
        $now = isset($expectations['now_ts']) && is_int($expectations['now_ts']) ? $expectations['now_ts'] : time();
        $leeway = isset($expectations['clock_skew_leeway']) && is_int($expectations['clock_skew_leeway']) ? $expectations['clock_skew_leeway'] : 0;
        if (! $this->validateExpiry($tokenClaims, $now, $leeway)) {
            $reasons[] = 'token_expired';
        }
        if (isset($expectations['nonce']) && is_string($expectations['nonce'])) {
            if (! $this->validateNonce($tokenClaims, $expectations['nonce'])) {
                $reasons[] = 'nonce_mismatch';
            }
        }
        if (isset($expectations['jwks_cache']) && $expectations['jwks_cache'] instanceof OidcJwksCacheInterface) {
            $cache = $expectations['jwks_cache'];
            if (isset($expectations['compact_jws']) && is_string($expectations['compact_jws'])) {
                $kid = isset($expectations['kid']) && is_string($expectations['kid']) ? $expectations['kid'] : null;
                $sigResult = $this->validateSignature($expectations['compact_jws'], $cache, $kid);
                if (! $sigResult['valid']) {
                    $reasons[] = 'signature_invalid:' . ($sigResult['error'] ?? 'unknown');
                }
            } else {
                $header = is_array($expectations['token_header'] ?? null) ? $expectations['token_header'] : [];
                $kid = isset($expectations['kid']) && is_string($expectations['kid']) ? $expectations['kid'] : null;
                if (! $this->validateSignaturePresent($header, $cache, $kid)) {
                    $reasons[] = 'signature_jwk_missing';
                }
            }
        }

        return [
            'valid' => $reasons === [],
            'reason_codes' => $reasons,
        ];
    }
}
