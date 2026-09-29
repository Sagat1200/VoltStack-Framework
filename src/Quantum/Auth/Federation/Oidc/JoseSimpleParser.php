<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

/**
 * Naive JWT / JWS parser RS256 y ES256 (solo compact formato 3 partes base64url).
 * Sin librerías externas; no valida firma (solo extrae estructuras). Clase separada
 * del OidcIdentityTokenValidator para testing unitario directo del parseo.
 */
final class JoseSimpleParser
{
    /**
     * @param string $compactJws "header.payload.signature"
     * @return array{header_b64u:string, payload_b64u:string, signature_b64u:string, signing_input:string}|null
     */
    public function split(string $compactJws): ?array
    {
        $parts = explode('.', $compactJws);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        if ($h === '' || $p === '' || $s === '') {
            return null;
        }
        return [
            'header_b64u' => $h,
            'payload_b64u' => $p,
            'signature_b64u' => $s,
            'signing_input' => $h . '.' . $p,
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

    /**
     * @param string $compactJws
     * @return array{header:array<string, mixed>, payload:array<string, mixed>, signing_input:string, signature_raw:string|null}|null
     */
    public function parse(string $compactJws): ?array
    {
        $split = $this->split($compactJws);
        if ($split === null) {
            return null;
        }
        $hRaw = self::b64uDecode($split['header_b64u']);
        $pRaw = self::b64uDecode($split['payload_b64u']);
        if ($hRaw === null || $pRaw === null) {
            return null;
        }
        $header = json_decode($hRaw, true);
        $payload = json_decode($pRaw, true);
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

    public function algOf(array $header): ?string
    {
        $alg = $header['alg'] ?? null;
        return is_string($alg) && in_array($alg, ['RS256', 'ES256', 'PS256'], true) ? $alg : null;
    }

    public function kidOf(array $header): ?string
    {
        $kid = $header['kid'] ?? null;
        return is_string($kid) && trim($kid) !== '' ? $kid : null;
    }
}
