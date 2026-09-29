<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use InvalidArgumentException;
use Quantum\Auth\Contracts\PasskeyCryptoVerifierInterface;

final class PasskeyRegistrationCeremony
{
    public function __construct(
        private readonly RelyingPartyConfig $rp,
        private readonly ?PasskeyCryptoVerifierInterface $cryptoVerifier = null,
    ) {
    }

    /**
     * @return array{challenge: string, rp: array{id: string, name: string}, user: array{id: string, name: string, display_name: string}}
     */
    public function beginRegistration(string $userHandle, string $userName, string $displayName): array
    {
        $challenge = bin2hex(random_bytes(32));

        return [
            'challenge' => $challenge,
            'rp' => [
                'id' => $this->rp->rpId,
                'name' => $this->rp->rpName,
            ],
            'user' => [
                'id' => $userHandle,
                'name' => $userName,
                'display_name' => $displayName,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @deprecated Use finishAttestation() for crypto-real validation
     */
    public function finishRegistration(array $response): PasskeyCredentialRecord
    {
        if ($this->cryptoVerifier !== null) {
            $response['expected_challenge'] = (string) ($response['expected_challenge'] ?? '');
            $response['expected_origin'] = (string) ($response['expected_origin'] ?? '');
            return $this->cryptoVerifier->verifyAttestation($response, $this->rp);
        }

        $credentialId = is_string($response['credential_id'] ?? null) && trim((string) $response['credential_id']) !== ''
            ? trim((string) $response['credential_id'])
            : 'reg_cred_' . bin2hex(random_bytes(16));
        $userHandle = is_string($response['user_handle'] ?? null) ? trim((string) $response['user_handle']) : 'anon_user';
        $publicKey = is_string($response['credential_public_key'] ?? null) && trim((string) $response['credential_public_key']) !== ''
            ? trim((string) $response['credential_public_key'])
            : 'simulated_pubkey_' . bin2hex(random_bytes(24));
        $transports = array_values(array_filter(
            is_array($response['transports'] ?? null) ? $response['transports'] : [],
            static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
        ));

        return new PasskeyCredentialRecord(
            credentialId: $credentialId,
            credentialPublicKey: $publicKey,
            userHandle: $userHandle,
            rpId: $this->rp->rpId,
            signCount: 0,
            createdAt: time(),
            transports: $transports,
        );
    }

    /**
     * @param array{
     *   client_data_json_b64: string,
     *   authenticator_data_b64: string,
     *   signature_b64: string,
     *   credential_id_b64: string,
     *   user_handle?: string,
     *   transports?: list<string>,
     *   attestation_format?: string
     * } $input
     */
    public function finishAttestation(array $input): PasskeyCredentialRecord
    {
        $clientDataRaw = self::b64uDecode($input['client_data_json_b64'] ?? '');
        $authDataRaw = self::b64uDecode($input['authenticator_data_b64'] ?? '');
        $signatureRaw = self::b64uDecode($input['signature_b64'] ?? '');
        $credentialIdRaw = self::b64uDecode($input['credential_id_b64'] ?? '');

        if ($clientDataRaw === '') {
            throw new InvalidArgumentException('attestation: missing client_data_json_b64');
        }
        if ($authDataRaw === '') {
            throw new InvalidArgumentException('attestation: missing authenticator_data_b64');
        }
        if ($credentialIdRaw === '') {
            throw new InvalidArgumentException('attestation: missing credential_id_b64');
        }

        try {
            $clientData = json_decode($clientDataRaw, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('attestation: invalid client_data JSON: ' . $e->getMessage(), 0, $e);
        }
        if (! is_array($clientData)) {
            throw new InvalidArgumentException('attestation: client_data must be JSON object');
        }

        $type = $clientData['type'] ?? null;
        if ($type !== 'webauthn.create') {
            throw new InvalidArgumentException(sprintf('attestation: expected client_data type=webauthn.create, got %s', is_string($type) ? $type : gettype($type)));
        }

        if (strlen($authDataRaw) < 37) {
            throw new InvalidArgumentException('attestation: authenticator_data too short for header');
        }

        $rpIdHash = substr($authDataRaw, 0, 32);
        $expectedRpIdHash = hash('sha256', $this->rp->rpId, true);
        if (! hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw new InvalidArgumentException('attestation: rp_id_hash does not match configured RelyingPartyConfig rpId');
        }

        $flagsByte = ord($authDataRaw[32]);
        $up = ($flagsByte & 0x01) !== 0;
        $uv = ($flagsByte & 0x04) !== 0;
        $atFlag = ($flagsByte & 0x40) !== 0;
        if (! $up) {
            throw new InvalidArgumentException('attestation: user_present flag not set');
        }

        $signCount = unpack('N', substr($authDataRaw, 33, 4)) ?: [1 => 0];
        $signCountInt = (int) $signCount[1];

        if (! $atFlag) {
            throw new InvalidArgumentException('attestation: attested_credential_data flag not set in authenticator_data');
        }

        $offset = 37;
        $aaguid = substr($authDataRaw, $offset, 16);
        $offset += 16;
        $credIdLenArr = unpack('n', substr($authDataRaw, $offset, 2));
        $credIdLen = (int) ($credIdLenArr[1] ?? 0);
        $offset += 2;
        $extractedCredId = substr($authDataRaw, $offset, $credIdLen);
        $offset += $credIdLen;

        if ($credentialIdRaw !== '' && ! hash_equals($credentialIdRaw, $extractedCredId)) {
            throw new InvalidArgumentException('attestation: credential_id from authData mismatches input credential_id_b64');
        }

        $credentialIdHex = bin2hex($extractedCredId);
        $remainingAuthData = substr($authDataRaw, $offset);
        if ($remainingAuthData === false || $remainingAuthData === '') {
            throw new InvalidArgumentException('attestation: missing COSE credentialPublicKey in authData');
        }

        $coseMap = $this->parseCborMapHead($remainingAuthData);
        if ($coseMap === null) {
            throw new InvalidArgumentException('attestation: cannot parse COSE_Key public key credential');
        }

        $coseKey = CoseKey::fromMap($coseMap);
        $pemPublicKey = $coseKey->toPemPublicKey();

        $clientDataHash = hash('sha256', $clientDataRaw, true);
        $verifyMessage = $authDataRaw . $clientDataHash;

        if ($signatureRaw !== '') {
            $verifier = new CoseSignatureVerifier();
            $signatureValid = $verifier->verify($verifyMessage, $signatureRaw, $coseKey);
            if (! $signatureValid) {
                throw new InvalidArgumentException('attestation: signature verification failed over authData||sha256(clientData)');
            }
        }

        $transports = [];
        if (isset($input['transports']) && is_array($input['transports'])) {
            $transports = array_values(array_filter(
                $input['transports'],
                static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
            ));
        }
        $userHandle = is_string($input['user_handle'] ?? null) && trim((string) $input['user_handle']) !== ''
            ? trim((string) $input['user_handle'])
            : bin2hex($aaguid);

        $sigAlgLabel = match ($coseKey->algorithm) {
            -7 => 'es256_p256_openssl',
            -257 => 'rs256_rsa_openssl',
            default => 'cose_alg_' . $coseKey->algorithm,
        };

        $record = new PasskeyCredentialRecord(
            credentialId: $credentialIdHex,
            credentialPublicKey: $pemPublicKey,
            userHandle: $userHandle,
            rpId: $this->rp->rpId,
            signCount: $signCountInt,
            createdAt: time(),
            transports: $transports,
        );

        return $record;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function parseCborMapHead(string $data): ?array
    {
        $offset = 0;
        if ($offset >= strlen($data)) {
            return null;
        }
        $initial = ord($data[$offset]);
        $major = ($initial >> 5) & 0x07;
        $addInfo = $initial & 0x1f;
        $offset++;

        if ($major !== 5) {
            return null;
        }

        if ($addInfo < 24) {
            $count = $addInfo;
        } elseif ($addInfo === 24) {
            if ($offset + 1 > strlen($data)) return null;
            $count = ord($data[$offset]);
            $offset++;
        } elseif ($addInfo === 25) {
            if ($offset + 2 > strlen($data)) return null;
            $count = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
            $offset += 2;
        } else {
            return null;
        }

        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $kRead = $this->cborReadValue($data, $offset);
            if ($kRead === null) return null;
            [$kVal, $kLen] = $kRead;
            $offset += $kLen;
            $vRead = $this->cborReadValue($data, $offset);
            if ($vRead === null) return null;
            [$vVal, $vLen] = $vRead;
            $offset += $vLen;
            if (is_int($kVal)) {
                $result[$kVal] = $vVal;
            }
        }

        return $result;
    }

    /**
     * @return array{0: mixed, 1: int}|null
     */
    private function cborReadValue(string $data, int $offset): ?array
    {
        if ($offset >= strlen($data)) return null;
        $start = $offset;
        $initial = ord($data[$offset]);
        $major = ($initial >> 5) & 0x07;
        $addInfo = $initial & 0x1f;
        $offset++;

        if ($addInfo < 24) {
            $arg = $addInfo;
        } elseif ($addInfo === 24) {
            if ($offset + 1 > strlen($data)) return null;
            $arg = ord($data[$offset]);
            $offset += 1;
        } elseif ($addInfo === 25) {
            if ($offset + 2 > strlen($data)) return null;
            $arg = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
            $offset += 2;
        } else {
            return null;
        }

        switch ($major) {
            case 0:
                return [$arg, $offset - $start];
            case 1:
                return [-1 - $arg, $offset - $start];
            case 2:
                if ($offset + (int) $arg > strlen($data)) return null;
                $bytes = substr($data, $offset, (int) $arg);
                $offset += (int) $arg;
                return [$bytes, $offset - $start];
            default:
                return null;
        }
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
