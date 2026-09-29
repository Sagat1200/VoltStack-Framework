<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use InvalidArgumentException;

final class CoseSignatureVerifier
{
    public function verify(string $message, string $signature, CoseKey $key): bool
    {
        $publicKeyPem = $key->toPemPublicKey();
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        switch ($key->algorithm) {
            case -7:
                return $this->verifyEs256($message, $signature, $publicKey);
            case -257:
                return $this->verifyRs256($message, $signature, $publicKey);
            default:
                throw new InvalidArgumentException(sprintf('Unsupported COSE algorithm: %d (-7=ES256, -257=RS256)', $key->algorithm));
        }
    }

    private function verifyEs256(string $message, string $signature, mixed $publicKey): bool
    {
        if (strlen($signature) !== 64) {
            return false;
        }

        $r = substr($signature, 0, 32);
        $s = substr($signature, 32, 32);
        $derSig = $this->ecRawToDer($r, $s);

        $result = openssl_verify($message, $derSig, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    private function verifyRs256(string $message, string $signature, mixed $publicKey): bool
    {
        $result = openssl_verify($message, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    private function ecRawToDer(string $r, string $s): string
    {
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if ($r === '') {
            $r = "\x00";
        }
        if ($s === '') {
            $s = "\x00";
        }

        if (ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $derR = "\x02" . $this->derLength(strlen($r)) . $r;
        $derS = "\x02" . $this->derLength(strlen($s)) . $s;

        return "\x30" . $this->derLength(strlen($derR) + strlen($derS)) . $derR . $derS;
    }

    private function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        $remaining = $len;
        while ($remaining > 0) {
            $bytes = chr($remaining & 0xff) . $bytes;
            $remaining >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
