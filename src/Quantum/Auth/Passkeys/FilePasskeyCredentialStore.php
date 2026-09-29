<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

use InvalidArgumentException;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use RuntimeException;

final class FilePasskeyCredentialStore implements PasskeyCredentialStoreInterface
{
    private const FILE_PREFIX = 'passkey_';
    private const FILE_SUFFIX = '.json';
    private const HKDF_INFO = 'quantum.auth.passkeys.credential.storage.v1';
    private const ENC_ALG = 'aes-256-gcm';
    private const KDF_ALG = 'hkdf-sha256';

    private readonly string $storagePath;
    private readonly ?string $encryptionKey;

    public function __construct(string $storagePath, ?string $encryptionKey = null)
    {
        if ($storagePath === '') {
            throw new InvalidArgumentException('FilePasskeyCredentialStore storage path cannot be empty.');
        }

        $normalized = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $storagePath), DIRECTORY_SEPARATOR);

        if (! is_dir($normalized) && ! @mkdir($normalized, 0755, true) && ! is_dir($normalized)) {
            throw new InvalidArgumentException(sprintf('Storage path does not exist and cannot be created: %s', $normalized));
        }

        $this->storagePath = $normalized;
        $this->encryptionKey = ($encryptionKey !== null && trim($encryptionKey) !== '') ? $encryptionKey : null;
    }

    public function findByCredentialId(string $credentialId): ?PasskeyCredentialRecord
    {
        $safeName = $this->sanitizeName($credentialId);
        $path = $this->filePathFor($safeName);

        if (! is_file($path)) {
            return null;
        }

        $data = $this->readAndDecode($path);
        if ($data === null) {
            return null;
        }

        return PasskeyCredentialRecord::fromArray($data);
    }

    /**
     * @return list<PasskeyCredentialRecord>
     */
    public function listForUserHandle(string $userHandle): array
    {
        $results = [];
        $pattern = $this->storagePath . DIRECTORY_SEPARATOR . self::FILE_PREFIX . '*' . self::FILE_SUFFIX;
        $files = @glob($pattern);

        if (! is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            $data = $this->readAndDecode($file);
            if ($data === null) {
                continue;
            }

            $handle = $data['user_handle'] ?? null;
            if (! is_string($handle) || ! hash_equals($userHandle, $handle)) {
                continue;
            }

            try {
                $results[] = PasskeyCredentialRecord::fromArray($data);
            } catch (\Throwable) {
                continue;
            }
        }

        return $results;
    }

    public function save(PasskeyCredentialRecord $record): void
    {
        $safeName = $this->sanitizeName($record->credentialId);
        $path = $this->filePathFor($safeName);

        $payload = $record->toArray();
        $encoded = $this->encodePayload($payload);

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create credential storage directory [%s].', $dir));
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $encoded, LOCK_EX);

        if ($written === false) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to write credential file [%s].', $path));
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to finalize credential file [%s].', $path));
        }
    }

    public function revoke(string $credentialId): bool
    {
        $safeName = $this->sanitizeName($credentialId);
        $path = $this->filePathFor($safeName);

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path) !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readAndDecode(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($envelope)) {
            return null;
        }

        $isEncrypted = isset($envelope['enc'], $envelope['ciphertext_b64']);
        if (! $isEncrypted) {
            return $envelope;
        }

        return $this->decryptEnvelope($envelope);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        if ($this->encryptionKey === null || trim($this->encryptionKey) === '') {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $envelope = $this->encryptPayload($payload);

        return json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{enc: string, kdf: string, iv_b64: string, tag_b64: string, ciphertext_b64: string}
     */
    private function encryptPayload(array $payload): array
    {
        $dataKey = $this->deriveDataKey();
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ivLen = openssl_cipher_iv_length(self::ENC_ALG);
        if ($ivLen === false || $ivLen <= 0) {
            $ivLen = 12;
        }
        $iv = random_bytes($ivLen);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::ENC_ALG,
            $dataKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('AES-256-GCM encryption of passkey credential record failed.');
        }

        return [
            'enc' => self::ENC_ALG,
            'kdf' => self::KDF_ALG,
            'iv_b64' => base64_encode($iv),
            'tag_b64' => base64_encode($tag),
            'ciphertext_b64' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|null
     */
    private function decryptEnvelope(array $envelope): ?array
    {
        $encAlg = is_string($envelope['enc'] ?? null) ? $envelope['enc'] : '';
        $kdf = is_string($envelope['kdf'] ?? null) ? $envelope['kdf'] : '';
        $ivB64 = is_string($envelope['iv_b64'] ?? null) ? $envelope['iv_b64'] : '';
        $tagB64 = is_string($envelope['tag_b64'] ?? null) ? $envelope['tag_b64'] : '';
        $ctB64 = is_string($envelope['ciphertext_b64'] ?? null) ? $envelope['ciphertext_b64'] : '';

        if ($encAlg !== self::ENC_ALG || $kdf !== self::KDF_ALG || $ctB64 === '' || $ivB64 === '') {
            return null;
        }

        if ($this->encryptionKey === null || trim($this->encryptionKey) === '') {
            throw new RuntimeException('Encrypted passkey credential envelope found but no encryptionKey configured on store.');
        }

        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ciphertext = base64_decode($ctB64, true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            return null;
        }

        $dataKey = $this->deriveDataKey();

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ENC_ALG,
            $dataKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
        );

        if ($plaintext === false || trim($plaintext) === '') {
            return null;
        }

        try {
            $decoded = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function deriveDataKey(): string
    {
        $master = $this->encryptionKey ?? '';
        if (trim($master) === '') {
            throw new RuntimeException('FilePasskeyCredentialStore encryptionKey is empty; cannot derive AES-256 data key.');
        }

        $key = hash_hkdf('sha256', $master, 32, self::HKDF_INFO);
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('HKDF derivation for passkey credential AES-256 data key failed.');
        }

        return $key;
    }

    private function sanitizeName(string $raw): string
    {
        if ($raw === '') {
            throw new InvalidArgumentException('Credential ID cannot be empty');
        }

        if (preg_match('#[\\\\/]|\.\.#', $raw) === 1) {
            throw new InvalidArgumentException('Unsafe credential ID characters detected');
        }

        $clean = preg_replace('#[^A-Za-z0-9_\-]#', '_', trim($raw));

        if (! is_string($clean) || $clean === '') {
            return 'passkey_cred_' . bin2hex(random_bytes(6));
        }

        return $clean;
    }

    private function filePathFor(string $safeName): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . self::FILE_PREFIX . $safeName . self::FILE_SUFFIX;
    }
}
