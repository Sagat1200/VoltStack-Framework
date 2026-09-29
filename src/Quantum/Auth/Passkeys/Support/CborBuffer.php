<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys\Support;

/**
 * Mini lector CBOR (RFC 8949) suficiente para decodificar COSE_Key de WebAuthn.
 * NO es un parser CBOR general; solo soporta: uint, negint, bstr, map (profundidad 1-2).
 *
 * @internal para V2 crypto Passkeys FIDO2; no usar para otros fines.
 */
final class CborBuffer
{
    private readonly string $bytes;
    private int $offset = 0;
    private readonly int $length;

    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
        $this->length = strlen($bytes);
    }

    public static function fromBase64Url(string $base64Url): self
    {
        $b64 = strtr($base64Url, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            $raw = '';
        }
        return new self($raw);
    }

    public function remaining(): int
    {
        return $this->length - $this->offset;
    }

    /**
     * @return mixed
     */
    public function read()
    {
        if ($this->remaining() < 1) {
            return null;
        }
        $head = ord($this->bytes[$this->offset]);
        $this->offset++;
        $major = $head >> 5 & 0x07;
        $info = $head & 0x1F;

        $arg = $this->readUintInfo($info);
        if ($arg === null) {
            return null;
        }

        return match ($major) {
            0 => $arg,
            1 => -1 - $arg,
            2 => $this->readByteString($arg),
            3 => $this->readTextString($arg),
            4 => $this->readArray($arg),
            5 => $this->readMap($arg),
            default => null,
        };
    }

    private function readUintInfo(int $info): ?int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => null,
        };
        if ($bytes === null || $this->remaining() < $bytes) {
            return null;
        }
        $v = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $v = ($v << 8) | ord($this->bytes[$this->offset + $i]);
        }
        $this->offset += $bytes;
        return $v;
    }

    private function readByteString(int $len): ?string
    {
        if ($this->remaining() < $len) {
            return null;
        }
        $out = substr($this->bytes, $this->offset, $len);
        $this->offset += $len;
        return $out;
    }

    private function readTextString(int $len): ?string
    {
        return $this->readByteString($len);
    }

    /**
     * @return list<mixed>|null
     */
    private function readArray(int $len): ?array
    {
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $out[] = $this->read();
        }
        return $out;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function readMap(int $len): ?array
    {
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $k = $this->read();
            if ($k === null) {
                return null;
            }
            $v = $this->read();
            if (is_int($k) || is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
