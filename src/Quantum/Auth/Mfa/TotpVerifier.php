<?php

declare(strict_types=1);

namespace Quantum\Auth\Mfa;

final class TotpVerifier
{
    public function verify(
        string $base32Secret,
        string $code,
        ?int $timestamp = null,
        int $period = 30,
        int $digits = 6,
        int $window = 1,
        string $algorithm = 'sha1',
    ): bool {
        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '' || strlen($normalizedCode) !== max(6, $digits)) {
            return false;
        }

        $secret = $this->decodeBase32($base32Secret);
        if ($secret === '') {
            return false;
        }

        $timestamp ??= time();
        $period = max(1, $period);
        $digits = max(6, $digits);
        $window = max(0, $window);
        $counter = intdiv($timestamp, $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->generateForCounter($secret, $counter + $offset, $digits, $algorithm), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function generate(
        string $base32Secret,
        ?int $timestamp = null,
        int $period = 30,
        int $digits = 6,
        string $algorithm = 'sha1',
    ): string {
        $secret = $this->decodeBase32($base32Secret);
        if ($secret === '') {
            return '';
        }

        $timestamp ??= time();
        $period = max(1, $period);

        return $this->generateForCounter(
            $secret,
            intdiv($timestamp, $period),
            max(6, $digits),
            $algorithm,
        );
    }

    private function generateForCounter(string $secret, int $counter, int $digits, string $algorithm): string
    {
        $algo = $this->normalizeAlgorithm($algorithm);
        $counterBytes = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac($algo, $counterBytes, $secret, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment);
        $binary = ((int) ($value[1] ?? 0)) & 0x7fffffff;
        $otp = $binary % (10 ** $digits);

        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    private function normalizeCode(string $code): string
    {
        return preg_replace('/\D+/', '', trim($code)) ?? '';
    }

    private function normalizeAlgorithm(string $algorithm): string
    {
        $normalized = strtolower(trim($algorithm));

        return match ($normalized) {
            'sha256' => 'sha256',
            'sha512' => 'sha512',
            default => 'sha1',
        };
    }

    private function decodeBase32(string $input): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        if ($clean === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($clean) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
