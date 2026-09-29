<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\PasskeyCryptoVerifierInterface;
use Quantum\Auth\Passkeys\Support\CoseKey;

final class PasskeyAssertionCeremony
{
    public function __construct(
        private readonly RelyingPartyConfig $rp,
        private readonly ?PasskeyCryptoVerifierInterface $cryptoVerifier = null,
    ) {
    }

    /**
     * @return array{challenge: string, rp_id: string, user_handle: string|null}
     */
    public function beginAssertion(?string $userHandle = null): array
    {
        $challenge = bin2hex(random_bytes(32));
        return [
            'challenge' => $challenge,
            'rp_id' => $this->rp->rpId,
            'user_handle' => $userHandle,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @deprecated Use verifyAssertion() for crypto-real signature validation
     */
    public function finishAssertion(array $response): AssertionResult
    {
        $credentialId = is_string($response['credential_id'] ?? null) ? trim((string) $response['credential_id']) : null;
        $userHandle = is_string($response['user_handle'] ?? null) ? trim((string) $response['user_handle']) : null;
        $prevSignCount = isset($response['previous_sign_count']) && is_numeric($response['previous_sign_count'])
            ? (int) $response['previous_sign_count']
            : 0;

        if ($this->cryptoVerifier !== null && $credentialId !== null && $credentialId !== '') {
            $pkeyFallback = (string) ($response['credential_public_key'] ?? '');
            if ($pkeyFallback === '') {
                $pkeyFallback = "alg:-7;pem:-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==\n-----END PUBLIC KEY-----\n";
            }
            $fakeRecord = new PasskeyCredentialRecord(
                credentialId: $credentialId,
                credentialPublicKey: $pkeyFallback,
                userHandle: (string) $userHandle,
                rpId: $this->rp->rpId,
                signCount: $prevSignCount,
            );
            try {
                $response['expected_challenge'] = (string) ($response['expected_challenge'] ?? '');
                $result = $this->cryptoVerifier->verifyAssertion($response, $fakeRecord, $prevSignCount);
                return $result;
            } catch (\Throwable) {
                $incremented = $prevSignCount + 1;
                return new AssertionResult(
                    isValid: false,
                    credentialId: $credentialId,
                    userHandle: $userHandle,
                    signCountIncremented: 0,
                    signatureVerified: false,
                    newCounter: $prevSignCount,
                    metadata: [
                        'rp_id' => $this->rp->rpId,
                        'simulated' => true,
                        'skeleton_version' => '083_fallback',
                    ],
                );
            }
        }

        $valid = $credentialId !== null && $credentialId !== '';
        $incremented = $prevSignCount + 1;

        return new AssertionResult(
            isValid: $valid,
            credentialId: $credentialId,
            userHandle: $userHandle,
            signCountIncremented: $valid ? $incremented : 0,
            signatureVerified: false,
            newCounter: $valid ? $incremented : 0,
            metadata: [
                'rp_id' => $this->rp->rpId,
                'simulated' => true,
                'skeleton_version' => '082_v1',
            ],
        );
    }

    /**
     * @param array{
     *   client_data_json_b64: string,
     *   authenticator_data_b64: string,
     *   signature_b64: string,
     *   credential_id_b64: string,
     *   user_handle?: string
     * } $input
     */
    public function verifyAssertion(array $input, PasskeyCredentialStoreInterface $store): AssertionResult
    {
        $failure = static function (string $reason, ?string $credentialId = null, ?string $userHandle = null): AssertionResult {
            return new AssertionResult(
                isValid: false,
                credentialId: $credentialId,
                userHandle: $userHandle,
                signCountIncremented: 0,
                metadata: [
                    'simulated' => false,
                    'failure_reason' => $reason,
                ],
            );
        };

        $clientDataRaw = self::b64uDecode($input['client_data_json_b64'] ?? '');
        $authDataRaw = self::b64uDecode($input['authenticator_data_b64'] ?? '');
        $signatureRaw = self::b64uDecode($input['signature_b64'] ?? '');
        $credentialIdRaw = self::b64uDecode($input['credential_id_b64'] ?? '');

        if ($clientDataRaw === '' || $authDataRaw === '' || $signatureRaw === '' || $credentialIdRaw === '') {
            return $failure('missing_required_fields');
        }

        try {
            $clientData = json_decode($clientDataRaw, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $failure('invalid_client_data_json');
        }
        if (! is_array($clientData)) {
            return $failure('invalid_client_data_object');
        }

        $type = $clientData['type'] ?? null;
        if ($type !== 'webauthn.get') {
            return $failure('client_data_type_mismatch');
        }

        if (strlen($authDataRaw) < 37) {
            return $failure('auth_data_too_short');
        }

        $rpIdHash = substr($authDataRaw, 0, 32);
        $expectedRpIdHash = hash('sha256', $this->rp->rpId, true);
        if (! hash_equals($expectedRpIdHash, $rpIdHash)) {
            return $failure('rp_id_hash_mismatch');
        }

        $flagsByte = ord($authDataRaw[32]);
        $up = ($flagsByte & 0x01) !== 0;
        if (! $up) {
            return $failure('user_present_flag_not_set');
        }
        $signCountArr = unpack('N', substr($authDataRaw, 33, 4));
        $authSignCount = (int) ($signCountArr[1] ?? 0);

        $credentialIdHex = bin2hex($credentialIdRaw);
        $stored = $store->findByCredentialId($credentialIdHex);
        if ($stored === null) {
            return $failure('credential_not_found', $credentialIdHex);
        }

        $pem = $stored->credentialPublicKey;
        if ($pem === '' || ! str_starts_with($pem, '-----BEGIN')) {
            return $failure('stored_credential_public_key_invalid', $credentialIdHex);
        }

        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return $failure('cannot_load_stored_public_key', $credentialIdHex);
        }
        $details = openssl_pkey_get_details($publicKey);
        $type = $details['type'] ?? -1;
        $coseMap = null;
        if ($type === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1') {
            $coseMap = [
                1 => 2,
                3 => -7,
                -1 => 1,
                -2 => $details['ec']['x'],
                -3 => $details['ec']['y'],
            ];
            $algLabel = 'es256_p256_openssl';
            $openSslAlg = OPENSSL_ALGO_SHA256;
            $isEc = true;
        } elseif ($type === OPENSSL_KEYTYPE_RSA) {
            $coseMap = [
                1 => 3,
                3 => -257,
                -1 => $details['rsa']['n'],
                -2 => $details['rsa']['e'],
            ];
            $algLabel = 'rs256_rsa_openssl';
            $openSslAlg = OPENSSL_ALGO_SHA256;
            $isEc = false;
        } else {
            return $failure('unsupported_stored_public_key_type', $credentialIdHex);
        }

        $coseKey = CoseKey::fromMap($coseMap);
        $clientDataHash = hash('sha256', $clientDataRaw, true);
        $verifyMessage = $authDataRaw . $clientDataHash;

        $signatureToVerify = $signatureRaw;
        if ($isEc && strlen($signatureRaw) === 64) {
            $signatureToVerify = $this->ecRawToDer(
                substr($signatureRaw, 0, 32),
                substr($signatureRaw, 32, 32),
            );
        }

        $verifyResult = openssl_verify($verifyMessage, $signatureToVerify, $publicKey, $openSslAlg);
        if ($verifyResult !== 1) {
            return $failure('signature_invalid', $credentialIdHex, $stored->userHandle);
        }

        $previousCount = $stored->signCount;
        if ($authSignCount !== 0 && $authSignCount <= $previousCount) {
            return $failure('sign_count_rollback', $credentialIdHex, $stored->userHandle);
        }

        $updatedRecord = new PasskeyCredentialRecord(
            credentialId: $stored->credentialId,
            credentialPublicKey: $stored->credentialPublicKey,
            userHandle: $stored->userHandle,
            rpId: $stored->rpId,
            signCount: $authSignCount,
            createdAt: $stored->createdAt,
            transports: $stored->transports,
        );
        $store->save($updatedRecord);

        $userHandle = is_string($input['user_handle'] ?? null) && trim((string) $input['user_handle']) !== ''
            ? trim((string) $input['user_handle'])
            : $stored->userHandle;

        return new AssertionResult(
            isValid: true,
            credentialId: $credentialIdHex,
            userHandle: $userHandle,
            signCountIncremented: $authSignCount,
            signatureVerified: true,
            newCounter: $authSignCount,
            metadata: [
                'simulated' => false,
                'signature_alg' => $algLabel,
                'previous_sign_count' => $previousCount,
                'user_verified' => ($flagsByte & 0x04) !== 0,
            ],
        );
    }

    private function ecRawToDer(string $r, string $s): string
    {
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if ($r === '') { $r = "\x00"; }
        if ($s === '') { $s = "\x00"; }
        if (ord($r[0]) >= 0x80) { $r = "\x00" . $r; }
        if (ord($s[0]) >= 0x80) { $s = "\x00" . $s; }

        $derR = "\x02" . $this->derLength(strlen($r)) . $r;
        $derS = "\x02" . $this->derLength(strlen($s)) . $s;
        return "\x30" . $this->derLength(strlen($derR) + strlen($derS)) . $derR . $derS;
    }

    private function derLength(int $len): string
    {
        if ($len < 0x80) return chr($len);
        $bytes = '';
        $remaining = $len;
        while ($remaining > 0) {
            $bytes = chr($remaining & 0xff) . $bytes;
            $remaining >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function b64uDecode(string $value): string
    {
        $trimmed = rtrim($value, " \t\n\r\0\x0B=");
        if ($trimmed === '') {
            return '';
        }
        $padded = strtr($trimmed, '-_', '+/') . str_repeat('=', (4 - strlen($trimmed) % 4) % 4);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? '' : $decoded;
    }
}
