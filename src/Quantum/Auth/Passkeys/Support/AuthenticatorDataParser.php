<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Support;

/**
 * Decodifica authenticatorData binario desde WebAuthn (spec W3C).
 *
 * Estructura:
 *   32 bytes  rpIdHash
 *   1 byte    flags (bit0 UP, bit2 UV, bit6 AT = attested credential data present, bit7 ED = extension data)
 *   4 bytes   signCount (BE uint32)
 *   [si AT=1] attestedCredentialData:
 *     16 bytes   aaguid
 *     2 bytes    credentialIdLength (BE uint16)
 *     L bytes    credentialId
 *     N bytes    credentialPublicKey (CBOR COSE_Key)
 *
 * @internal V2 Passkeys crypto real.
 */
final class AuthenticatorDataParser
{
    /**
     * @param string $authData  authenticatorData crudo (binario)
     * @return array{
     *     rp_id_hash: string,
     *     up: bool,
     *     uv: bool,
     *     at_present: bool,
     *     ed_present: bool,
     *     sign_count: int,
     *     aaguid?: string,
     *     credential_id?: string,
     *     credential_public_key_cbor?: string,
     * }|null
     */
    public function parse(string $authData): ?array
    {
        $len = strlen($authData);
        if ($len < 37) {
            return null;
        }

        $rpIdHash = substr($authData, 0, 32);
        $flagsByte = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1] & 0xFFFFFFFF;

        $up = ($flagsByte & 0x01) !== 0;
        $uv = ($flagsByte & 0x04) !== 0;
        $atPresent = ($flagsByte & 0x40) !== 0;
        $edPresent = ($flagsByte & 0x80) !== 0;

        $out = [
            'rp_id_hash' => $rpIdHash,
            'up' => $up,
            'uv' => $uv,
            'at_present' => $atPresent,
            'ed_present' => $edPresent,
            'sign_count' => $signCount,
        ];

        if (! $atPresent) {
            return $out;
        }

        if ($len < 37 + 18) {
            return null;
        }
        $offset = 37;
        $aaguid = substr($authData, $offset, 16);
        $offset += 16;
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        if ($len < $offset + $credIdLen) {
            return null;
        }
        $credentialId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;
        $coseCbor = substr($authData, $offset);

        $out['aaguid'] = $aaguid;
        $out['credential_id'] = $credentialId;
        $out['credential_public_key_cbor'] = $coseCbor;
        return $out;
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): ?string
    {
        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($b64, true);
        return $out === false ? null : $out;
    }
}
