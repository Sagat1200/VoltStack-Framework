<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use InvalidArgumentException;

final class CoseKey
{
    public readonly int $kty;
    public readonly int $algorithm;
    public readonly ?int $curve;
    public readonly ?string $x;
    public readonly ?string $y;
    public readonly ?string $n;
    public readonly ?string $e;

    /**
     * @param array<int, mixed> $map
     */
    public static function fromMap(array $map): self
    {
        $kty = isset($map[1]) && is_int($map[1]) ? $map[1] : 0;
        $alg = isset($map[3]) && is_int($map[3]) ? $map[3] : 0;

        if ($kty === 2) {
            $curve = isset($map[-1]) && is_int($map[-1]) ? $map[-1] : 0;
            $x = isset($map[-2]) && is_string($map[-2]) ? $map[-2] : '';
            $y = isset($map[-3]) && is_string($map[-3]) ? $map[-3] : '';
            return new self(kty: $kty, algorithm: $alg, curve: $curve, x: $x, y: $y, n: null, e: null);
        }

        if ($kty === 3) {
            $n = isset($map[-1]) && is_string($map[-1]) ? $map[-1] : '';
            $e = isset($map[-2]) && is_string($map[-2]) ? $map[-2] : '';
            return new self(kty: $kty, algorithm: $alg, curve: null, x: null, y: null, n: $n, e: $e);
        }

        throw new InvalidArgumentException(sprintf('Unsupported COSE key type: %d', $kty));
    }

    private function __construct(
        int $kty,
        int $algorithm,
        ?int $curve,
        ?string $x,
        ?string $y,
        ?string $n,
        ?string $e,
    ) {
        $this->kty = $kty;
        $this->algorithm = $algorithm;
        $this->curve = $curve;
        $this->x = $x;
        $this->y = $y;
        $this->n = $n;
        $this->e = $e;
    }

    public function toPemPublicKey(): string
    {
        if ($this->kty === 2) {
            return $this->ecToPem();
        }
        if ($this->kty === 3) {
            return $this->rsaToPem();
        }
        throw new InvalidArgumentException('Cannot convert unsupported COSE kty to PEM');
    }

    private function ecToPem(): string
    {
        if ($this->curve !== 1) {
            throw new InvalidArgumentException(sprintf('Unsupported EC curve: %d (expected 1 = P-256)', $this->curve ?? 0));
        }
        if ($this->x === null || $this->y === null || strlen($this->x) !== 32 || strlen($this->y) !== 32) {
            throw new InvalidArgumentException('Invalid EC point coordinates for P-256');
        }

        $uncompressed = "\x04" . $this->x . $this->y;
        $der = $this->derSequence(
            $this->derSequence(
                $this->derOid("\x2a\x86\x48\xce\x3d\x02\x01") .
                $this->derOid("\x2a\x86\x48\xce\x3d\x03\x01\x07")
            ) .
            $this->derBitString($uncompressed)
        );

        return $this->derToPem($der, 'PUBLIC KEY');
    }

    private function rsaToPem(): string
    {
        if ($this->n === null || $this->e === null || $this->n === '' || $this->e === '') {
            throw new InvalidArgumentException('RSA modulus or exponent missing');
        }

        $nBin = ltrim($this->n, "\x00");
        $eBin = ltrim($this->e, "\x00");
        if ($nBin === '') {
            $nBin = "\x00";
        }

        if (ord($nBin[0]) >= 0x80) {
            $nBin = "\x00" . $nBin;
        }
        if (ord($eBin[0]) >= 0x80) {
            $eBin = "\x00" . $eBin;
        }

        $rsaPublicKey = $this->derSequence(
            $this->derInteger($nBin) .
            $this->derInteger($eBin)
        );

        $der = $this->derSequence(
            $this->derSequence(
                $this->derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") .
                "\x05\x00"
            ) .
            $this->derBitString($rsaPublicKey)
        );

        return $this->derToPem($der, 'PUBLIC KEY');
    }

    private function derToPem(string $der, string $label): string
    {
        $base64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$label}-----\n" . $base64 . "-----END {$label}-----\n";
    }

    private function derSequence(string $content): string
    {
        return "\x30" . $this->derLength(strlen($content)) . $content;
    }

    private function derInteger(string $bytes): string
    {
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derBitString(string $bytes): string
    {
        return "\x03" . $this->derLength(strlen($bytes) + 1) . "\x00" . $bytes;
    }

    private function derOid(string $encoded): string
    {
        return "\x06" . $this->derLength(strlen($encoded)) . $encoded;
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
