<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

use Quantum\Auth\Contracts\DistributedPasswordGovernanceProviderInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MultiFactorIdentityProviderInterface;
use Quantum\Auth\Contracts\MutableIdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordLifecycleAwareProviderInterface;
use Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface;
use Quantum\Auth\Mfa\TotpVerifier;
use Quantum\Auth\Passwords\PasswordRotationReceipt;
use Quantum\Config\ConfigRepository;

final class LocalIdentityProvider implements IdentityProviderInterface, PasswordRehashingIdentityProviderInterface, MultiFactorIdentityProviderInterface, MutableIdentityProviderInterface, PasswordLifecycleAwareProviderInterface, DistributedPasswordGovernanceProviderInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function findByIdentifier(string $identifier): ?IdentityInterface
    {
        $entry = $this->findEntry($identifier);

        if ($entry === null) {
            return null;
        }

        return new GenericIdentity(
            identifier: new IdentityIdentifier((string) ($entry['id'] ?? $identifier)),
            type: trim((string) ($entry['type'] ?? 'user')) !== '' ? trim((string) ($entry['type'] ?? 'user')) : 'user',
            attributes: $this->identityAttributes($entry, $identifier),
        );
    }

    public function passwordHashFor(IdentityInterface $identity): ?string
    {
        if ($identity instanceof GenericIdentity) {
            $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

            if (is_string($lookupIdentifier) && trim($lookupIdentifier) !== '') {
                $entry = $this->findEntry($lookupIdentifier);

                if ($entry !== null) {
                    $passwordHash = $entry['password_hash'] ?? null;

                    return is_string($passwordHash) && trim($passwordHash) !== '' ? $passwordHash : null;
                }
            }
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = (string) ($entry['id'] ?? '');
            if ($entryId !== '' && $entryId === (string) $identity->identifier()) {
                $passwordHash = $entry['password_hash'] ?? null;

                return is_string($passwordHash) && trim($passwordHash) !== '' ? $passwordHash : null;
            }
        }

        return null;
    }

    public function securityStateFor(IdentityInterface $identity): IdentitySecurityState
    {
        $entry = $this->entryForIdentity($identity);
        $state = strtolower(trim((string) ($entry['security_state'] ?? $entry['status'] ?? 'active')));

        return match ($state) {
            'disabled' => IdentitySecurityState::Disabled,
            'suspended' => IdentitySecurityState::Suspended,
            'locked' => IdentitySecurityState::Locked,
            default => IdentitySecurityState::Active,
        };
    }

    public function upgradePasswordHash(IdentityInterface $identity, string $passwordHash): bool
    {
        if (trim($passwordHash) === '') {
            return false;
        }

        $identities = $this->configuredIdentities();
        $updated = false;

        foreach ($identities as $index => $entry) {
            if (! is_array($entry) || ! $this->matchesIdentity($entry, $identity)) {
                continue;
            }

            $oldHash = is_string($entry['password_hash'] ?? null) && trim((string) $entry['password_hash']) !== ''
                ? (string) $entry['password_hash']
                : null;

            $entry['password_hash'] = $passwordHash;

            if (! isset($entry['password_created_at']) || ! is_int($entry['password_created_at']) || $entry['password_created_at'] <= 0) {
                $entry['password_created_at'] = time();
            }

            $entry['password_last_rotated_at'] = time();

            $history = isset($entry['password_rotation_history']) && is_array($entry['password_rotation_history'])
                ? array_values(array_filter($entry['password_rotation_history'], static fn (mixed $v): bool => is_string($v) && trim((string) $v) !== ''))
                : [];

            if ($oldHash !== null) {
                $history[] = $oldHash;
            }

            $historyDepth = $this->rotationHistoryDepth();
            if ($historyDepth > 0 && count($history) > $historyDepth) {
                $history = array_values(array_slice($history, -$historyDepth));
            }

            $entry['password_rotation_history'] = $history;

            $identities[$index] = $entry;
            $updated = true;
            break;
        }

        if (! $updated) {
            return false;
        }

        $this->config->set('auth.providers.local.identities', $identities);

        $storagePath = $this->storagePath();

        if ($storagePath === null) {
            return true;
        }

        return $this->persistStoredIdentities($storagePath, $identities);
    }

    public function requiresSecondFactor(IdentityInterface $identity): bool
    {
        $entry = $this->entryForIdentity($identity);

        if ($entry === null) {
            return false;
        }

        return $this->booleanEntryValue($entry, ['mfa_required', 'second_factor_required']);
    }

    public function supportsSecondFactor(IdentityInterface $identity): bool
    {
        return $this->availableSecondFactorMethods($identity) !== []
            || $this->requiresSecondFactor($identity);
    }

    public function availableSecondFactorMethods(IdentityInterface $identity): array
    {
        $entry = $this->entryForIdentity($identity);

        if ($entry === null) {
            return [];
        }

        $methods = [];

        if ($this->configuredSecondFactorCode($entry) !== null) {
            $methods[] = 'second_factor';
        }

        if ($this->totpEnabled() && $this->configuredTotpSecret($entry) !== null) {
            $methods[] = 'totp';
        }

        if ($this->recoveryCodesEnabled() && $this->configuredRecoveryCodes($entry) !== []) {
            $methods[] = 'recovery_code';
        }

        return array_values(array_unique($methods));
    }

    public function verifySecondFactor(IdentityInterface $identity, string $secondFactor, ?string $method = null): bool
    {
        $entry = $this->entryForIdentity($identity);

        if ($entry === null) {
            return false;
        }

        $normalizedMethod = $this->normalizeSecondFactorMethod($method);
        $normalizedCode = trim($secondFactor);

        return match ($normalizedMethod) {
            'totp' => $this->verifyTotpCode($entry, $normalizedCode),
            'recovery_code' => $this->consumeRecoveryCode($identity, $normalizedCode),
            'second_factor' => $this->verifyGenericSecondFactor($identity, $entry, $normalizedCode),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function verifyGenericSecondFactor(IdentityInterface $identity, array $entry, string $secondFactor): bool
    {
        $configuredCode = $this->configuredSecondFactorCode($entry);
        if ($configuredCode !== null && hash_equals($configuredCode, trim($secondFactor))) {
            return true;
        }

        if ($this->verifyTotpCode($entry, $secondFactor)) {
            return true;
        }

        return $this->consumeRecoveryCode($identity, $secondFactor);
    }

    public function passwordLifecycleMetadataFor(IdentityInterface $identity): array
    {
        $entry = $this->entryForIdentity($identity) ?? [];

        $createdAt = isset($entry['password_created_at']) && is_int($entry['password_created_at']) && $entry['password_created_at'] > 0
            ? $entry['password_created_at']
            : 0;

        $lastRotatedAt = isset($entry['password_last_rotated_at']) && is_int($entry['password_last_rotated_at']) && $entry['password_last_rotated_at'] > 0
            ? $entry['password_last_rotated_at']
            : 0;

        $expiresAt = isset($entry['password_expires_at']) && is_int($entry['password_expires_at']) && $entry['password_expires_at'] > 0
            ? $entry['password_expires_at']
            : null;

        $history = isset($entry['password_rotation_history']) && is_array($entry['password_rotation_history'])
            ? array_values(array_filter($entry['password_rotation_history'], static fn (mixed $v): bool => is_string($v) && trim((string) $v) !== ''))
            : [];

        $failedAttempts = isset($entry['failed_attempts']) && is_int($entry['failed_attempts'])
            ? max(0, $entry['failed_attempts'])
            : 0;

        $lockoutUntil = isset($entry['lockout_until']) && is_int($entry['lockout_until']) && $entry['lockout_until'] > 0
            ? $entry['lockout_until']
            : null;

        $lastFailedAttemptAt = isset($entry['last_failed_attempt_at']) && is_int($entry['last_failed_attempt_at']) && $entry['last_failed_attempt_at'] > 0
            ? $entry['last_failed_attempt_at']
            : null;

        try {
            $securityState = $this->securityStateFor($identity);
        } catch (\Throwable) {
            $securityState = IdentitySecurityState::Active;
        }

        $securityStateReason = isset($entry['security_state_reason']) && is_string($entry['security_state_reason']) && trim($entry['security_state_reason']) !== ''
            ? $entry['security_state_reason']
            : null;

        return [
            'password_created_at' => $createdAt,
            'password_last_rotated_at' => $lastRotatedAt,
            'password_expires_at' => $expiresAt,
            'password_rotation_history' => $history,
            'failed_attempts' => $failedAttempts,
            'lockout_until' => $lockoutUntil,
            'last_failed_attempt_at' => $lastFailedAttemptAt,
            'security_state' => $securityState->value,
            'security_state_reason' => $securityStateReason,
        ];
    }

    public function updateSecurityState(IdentityInterface $identity, IdentitySecurityState $state, ?string $reason = null): bool
    {
        return $this->updateEntry($identity, static function (array $entry) use ($state, $reason): array {
            $entry['security_state'] = $state->value;
            $entry['status'] = $state->value;
            if ($reason !== null && trim($reason) !== '') {
                $entry['security_state_reason'] = $reason;
            } else {
                unset($entry['security_state_reason']);
            }

            return $entry;
        });
    }

    public function setSecondFactorRequired(IdentityInterface $identity, bool $required): bool
    {
        return $this->updateEntry($identity, static function (array $entry) use ($required): array {
            $entry['mfa_required'] = $required;
            $entry['second_factor_required'] = $required;

            return $entry;
        });
    }

    public function recordFailedAuthentication(IdentityInterface $identity): void
    {
        $threshold = $this->lockoutAttemptsThreshold();
        $lockoutWindow = $this->lockoutWindowSeconds();

        $this->updateEntry($identity, function (array $entry) use ($threshold, $lockoutWindow): array {
            $lastFailedAt = isset($entry['last_failed_attempt_at']) && is_int($entry['last_failed_attempt_at'])
                ? $entry['last_failed_attempt_at']
                : 0;

            $current = time();
            $failedAttempts = isset($entry['failed_attempts']) && is_int($entry['failed_attempts'])
                ? $entry['failed_attempts']
                : 0;

            if ($lockoutWindow > 0 && $lastFailedAt > 0 && ($current - $lastFailedAt) > $lockoutWindow) {
                $failedAttempts = 0;
            }

            $failedAttempts++;
            $entry['failed_attempts'] = $failedAttempts;
            $entry['last_failed_attempt_at'] = $current;

            if ($threshold > 0 && $lockoutWindow > 0 && $failedAttempts >= $threshold) {
                $entry['lockout_until'] = $current + $lockoutWindow;
                $entry['security_state'] = IdentitySecurityState::Locked->value;
            }

            return $entry;
        });
    }

    public function clearFailedAuthentication(IdentityInterface $identity): void
    {
        $this->updateEntry($identity, static function (array $entry): array {
            $entry['failed_attempts'] = 0;
            unset($entry['last_failed_attempt_at']);
            unset($entry['lockout_until']);
            $state = strtolower(trim((string) ($entry['security_state'] ?? $entry['status'] ?? 'active')));
            if ($state === IdentitySecurityState::Locked->value) {
                $entry['security_state'] = IdentitySecurityState::Active->value;
                $entry['status'] = IdentitySecurityState::Active->value;
            }

            return $entry;
        });
    }

    public function isLockedOut(IdentityInterface $identity): bool
    {
        $entry = $this->entryForIdentity($identity);

        if ($entry === null) {
            return false;
        }

        $lockoutUntil = isset($entry['lockout_until']) && is_int($entry['lockout_until']) && $entry['lockout_until'] > 0
            ? $entry['lockout_until']
            : null;

        if ($lockoutUntil !== null && time() < $lockoutUntil) {
            return true;
        }

        $state = strtolower(trim((string) ($entry['security_state'] ?? $entry['status'] ?? 'active')));

        return $state === IdentitySecurityState::Locked->value;
    }

    public function unlock(IdentityInterface $identity): bool
    {
        return $this->updateEntry($identity, static function (array $entry): array {
            $entry['failed_attempts'] = 0;
            unset($entry['lockout_until']);
            unset($entry['last_failed_attempt_at']);
            $state = strtolower(trim((string) ($entry['security_state'] ?? $entry['status'] ?? 'active')));
            if ($state === IdentitySecurityState::Locked->value) {
                $entry['security_state'] = IdentitySecurityState::Active->value;
                $entry['status'] = IdentitySecurityState::Active->value;
            }

            return $entry;
        });
    }

    public function bulkInvalidateByIdentifierPrefix(string $identifierPrefix, string $reason): int
    {
        $prefix = strtolower(trim($identifierPrefix));
        if ($prefix === '') {
            return 0;
        }

        $identities = $this->configuredIdentities();
        $invalidated = 0;

        foreach ($identities as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $match = false;
            foreach (['identifier', 'email', 'username'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key])) {
                    $candidate = strtolower(trim($entry[$key]));
                    if ($candidate !== '' && str_starts_with($candidate, $prefix)) {
                        $match = true;
                        break;
                    }
                }
            }

            if (! $match) {
                continue;
            }

            $entry['security_state'] = IdentitySecurityState::Suspended->value;
            $entry['status'] = IdentitySecurityState::Suspended->value;
            if (trim($reason) !== '') {
                $entry['security_state_reason'] = $reason;
            }
            $entry['bulk_invalidated_at'] = time();
            $entry['bulk_invalidation_reason'] = $reason;
            $identities[$index] = $entry;
            $invalidated++;
        }

        if ($invalidated === 0) {
            return 0;
        }

        $this->config->set('auth.providers.local.identities', $identities);

        $storagePath = $this->storagePath();
        if ($storagePath === null) {
            return $invalidated;
        }

        return $this->persistStoredIdentities($storagePath, $identities) ? $invalidated : 0;
    }

    public function saveRotationReceipt(
        IdentityInterface $identity,
        string $previousHash,
        string $newHash,
        int $rotatedAt,
        string $rotatedByActorSessionPublicId,
        ?string $reason = null,
    ): bool {
        $rotatedAt = $rotatedAt > 0 ? $rotatedAt : time();
        $receipt = new PasswordRotationReceipt(
            identityId: (string) $identity->identifier(),
            previousHash: $previousHash,
            newHash: $newHash,
            rotatedAt: $rotatedAt,
            rotatedByActorSessionPublicId: $rotatedByActorSessionPublicId,
            reason: $reason,
        );

        $storagePath = $this->storagePath();
        if ($storagePath !== null) {
            $dir = dirname($storagePath) . DIRECTORY_SEPARATOR . 'rotation_receipts';
            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                $storagePath = null;
            } else {
                $identitySafe = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $identity->identifier()) ?: 'unknown';
                $fileName = $identitySafe . '_' . $rotatedAt . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.json';
                $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;
                $payload = json_encode($receipt->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $written = @file_put_contents($filePath, $payload . PHP_EOL);
                if ($written !== false) {
                    return true;
                }
            }
        }

        $key = 'auth.providers.local.rotation_receipts';
        $existing = $this->config->get($key, []);
        if (! is_array($existing)) {
            $existing = [];
        }
        $existing[] = $receipt->toArray();
        $this->config->set($key, $existing);

        return true;
    }

    public function retentionTierFor(IdentityInterface $identity): string
    {
        $entry = $this->entryForIdentity($identity);
        if ($entry !== null && isset($entry['retention_tier']) && is_string($entry['retention_tier']) && trim($entry['retention_tier']) !== '') {
            $tier = strtolower(trim($entry['retention_tier']));
            if (in_array($tier, ['low', 'medium', 'high'], true)) {
                return $tier;
            }
        }

        $configured = $this->config->get('auth.password.retention.default_tier');
        if (is_string($configured) && trim($configured) !== '') {
            $tier = strtolower(trim($configured));
            if (in_array($tier, ['low', 'medium', 'high'], true)) {
                return $tier;
            }
        }

        return 'medium';
    }

    public function credentialStrengthCheck(string $rawPassword): array
    {
        $issues = [];
        $score = 0;

        $len = strlen($rawPassword);
        if ($len < 8) {
            $issues[] = 'min_length';
        } else {
            $score += 20;
            if ($len >= 12) {
                $score += 10;
            }
            if ($len >= 16) {
                $score += 10;
            }
        }

        if (preg_match('/[A-Z]/', $rawPassword) === 1) {
            $score += 15;
        } else {
            $issues[] = 'no_uppercase';
        }

        if (preg_match('/[a-z]/', $rawPassword) === 1) {
            $score += 15;
        } else {
            $issues[] = 'no_lowercase';
        }

        if (preg_match('/[0-9]/', $rawPassword) === 1) {
            $score += 15;
        } else {
            $issues[] = 'no_digit';
        }

        if (preg_match('/[^A-Za-z0-9]/', $rawPassword) === 1) {
            $score += 15;
        } else {
            $issues[] = 'no_symbol';
        }

        $blacklist = [
            'password', '12345678', 'qwerty123', 'admin123', 'letmein1',
            'welcome1', 'password1', 'abc12345', '123456789', '1234567890',
            'iloveyou', 'sunshine', 'princess', 'football', 'baseball',
        ];
        if (in_array(strtolower($rawPassword), $blacklist, true)) {
            $score = min($score, 15);
            $issues[] = 'common_password';
        }

        return [
            'score' => min(100, max(0, $score)),
            'issues' => array_values($issues),
            'passes' => $score >= 50 && $len >= 8 && ! in_array('common_password', $issues, true),
        ];
    }

    public function multiDimLockoutThresholds(): array
    {
        $byIdentifier = $this->config->get('auth.password.lockout.multi_dim.by_identifier');
        $byDeviceRef = $this->config->get('auth.password.lockout.multi_dim.by_device_ref');
        $byIpPrefix = $this->config->get('auth.password.lockout.multi_dim.by_ip_prefix');
        $windowSeconds = $this->config->get('auth.password.lockout.multi_dim.window_seconds');

        return [
            'by_identifier' => (is_numeric($byIdentifier) && (int) $byIdentifier > 0) ? (int) $byIdentifier : 10,
            'by_device_ref' => (is_numeric($byDeviceRef) && (int) $byDeviceRef > 0) ? (int) $byDeviceRef : 15,
            'by_ip_prefix' => (is_numeric($byIpPrefix) && (int) $byIpPrefix > 0) ? (int) $byIpPrefix : 25,
            'window_seconds' => (is_numeric($windowSeconds) && (int) $windowSeconds > 0) ? (int) $windowSeconds : 900,
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function updateEntry(IdentityInterface $identity, callable $mutator): bool
    {
        $identities = $this->configuredIdentities();
        $updated = false;

        foreach ($identities as $index => $entry) {
            if (! is_array($entry) || ! $this->matchesIdentity($entry, $identity)) {
                continue;
            }

            $mutated = $mutator($entry);
            if (! is_array($mutated)) {
                break;
            }

            $identities[$index] = $mutated;
            $updated = true;
            break;
        }

        if (! $updated) {
            return false;
        }

        $this->config->set('auth.providers.local.identities', $identities);

        $storagePath = $this->storagePath();

        if ($storagePath === null) {
            return true;
        }

        return $this->persistStoredIdentities($storagePath, $identities);
    }

    private function lockoutAttemptsThreshold(): int
    {
        $value = $this->config->get('auth.password.lockout.attempts_threshold');

        if ($value === null) {
            return 0;
        }

        $value = is_numeric($value) ? (int) $value : -1;

        return max(0, $value);
    }

    private function lockoutWindowSeconds(): int
    {
        $value = $this->config->get('auth.password.lockout.window_seconds');

        if ($value === null) {
            return 0;
        }

        $value = is_numeric($value) ? (int) $value : -1;

        return max(0, $value);
    }

    private function rotationHistoryDepth(): int
    {
        $value = $this->config->get('auth.password.rotation_history_depth');

        if ($value === null) {
            $value = $this->config->get('auth.password.rotation_history_size');
        }

        if ($value === null) {
            return 0;
        }

        $value = is_numeric($value) ? (int) $value : -1;

        return max(0, $value);
    }

    /**
     * @return array<int, mixed>
     */
    private function configuredIdentities(): array
    {
        $storagePath = $this->storagePath();

        if ($storagePath !== null) {
            $stored = $this->loadStoredIdentities($storagePath);

            if ($stored !== null) {
                return $stored;
            }
        }

        $identities = $this->config->get('auth.providers.local.identities', []);

        return is_array($identities) ? array_values($identities) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEntry(string $identifier): ?array
    {
        $normalized = strtolower(trim($identifier));

        if ($normalized === '') {
            return null;
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['identifier', 'email', 'username'] as $key) {
                $candidate = isset($entry[$key]) ? strtolower(trim((string) $entry[$key])) : '';

                if ($candidate !== '' && $candidate === $normalized) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function identityAttributes(array $entry, string $identifier): array
    {
        $attributes = [];

        foreach ($entry as $key => $value) {
            if (in_array($key, [
                'password_hash',
                'mfa_code',
                'second_factor_code',
                'otp_code',
                'totp_secret',
                'totp_secret_base32',
                'mfa_totp_secret',
                'otp_secret',
                'recovery_codes',
                'recovery_code_hashes',
                'backup_codes',
            ], true)) {
                continue;
            }

            $attributes[$key] = $value;
        }

        $attributes['_provider_identifier_value'] = $identifier;

        return $attributes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryForIdentity(IdentityInterface $identity): ?array
    {
        if ($identity instanceof GenericIdentity) {
            $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

            if (is_string($lookupIdentifier) && trim($lookupIdentifier) !== '') {
                return $this->findEntry($lookupIdentifier);
            }
        }

        foreach ($this->configuredIdentities() as $entry) {
            if (is_array($entry) && $this->matchesIdentity($entry, $identity)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function matchesIdentity(array $entry, IdentityInterface $identity): bool
    {
        if ((string) ($entry['id'] ?? '') === (string) $identity->identifier()) {
            return true;
        }

        if (! $identity instanceof GenericIdentity) {
            return false;
        }

        $lookupIdentifier = $identity->attributes['_provider_identifier_value'] ?? null;

        if (! is_string($lookupIdentifier) || trim($lookupIdentifier) === '') {
            return false;
        }

        $normalizedLookup = strtolower(trim($lookupIdentifier));

        foreach (['identifier', 'email', 'username'] as $key) {
            $candidate = isset($entry[$key]) ? strtolower(trim((string) $entry[$key])) : '';

            if ($candidate !== '' && $candidate === $normalizedLookup) {
                return true;
            }
        }

        return false;
    }

    private function storagePath(): ?string
    {
        $path = $this->config->get('auth.providers.local.storage_path');

        return is_string($path) && trim($path) !== ''
            ? trim($path)
            : null;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function loadStoredIdentities(string $storagePath): ?array
    {
        if (! is_file($storagePath)) {
            return null;
        }

        $contents = file_get_contents($storagePath);

        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        $identities = $decoded['identities'] ?? $decoded;

        return is_array($identities) ? array_values($identities) : null;
    }

    /**
     * @param array<int, mixed> $identities
     */
    private function persistStoredIdentities(string $storagePath, array $identities): bool
    {
        $directory = dirname($storagePath);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return false;
        }

        $payload = json_encode(
            ['identities' => array_values($identities)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($payload)) {
            return false;
        }

        return file_put_contents($storagePath, $payload . PHP_EOL) !== false;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function configuredSecondFactorCode(array $entry): ?string
    {
        foreach (['mfa_code', 'second_factor_code', 'otp_code'] as $key) {
            $candidate = isset($entry[$key]) ? trim((string) $entry[$key]) : '';

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function configuredTotpSecret(array $entry): ?string
    {
        foreach (['totp_secret', 'totp_secret_base32', 'mfa_totp_secret', 'otp_secret'] as $key) {
            $candidate = isset($entry[$key]) ? trim((string) $entry[$key]) : '';

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function configuredRecoveryCodes(array $entry): array
    {
        foreach (['recovery_codes', 'recovery_code_hashes', 'backup_codes'] as $key) {
            $candidate = $entry[$key] ?? null;
            if (! is_array($candidate)) {
                continue;
            }

            return array_values(array_filter(array_map(
                static fn (mixed $value): string => is_string($value) ? trim($value) : '',
                $candidate,
            ), static fn (string $value): bool => $value !== ''));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function verifyTotpCode(array $entry, string $code): bool
    {
        if (! $this->totpEnabled()) {
            return false;
        }

        $secret = $this->configuredTotpSecret($entry);
        if ($secret === null) {
            return false;
        }

        return (new TotpVerifier())->verify(
            base32Secret: $secret,
            code: $code,
            timestamp: time(),
            period: $this->totpPeriod($entry),
            digits: $this->totpDigits($entry),
            window: $this->totpWindow(),
            algorithm: $this->totpAlgorithm($entry),
        );
    }

    private function consumeRecoveryCode(IdentityInterface $identity, string $providedCode): bool
    {
        if (! $this->recoveryCodesEnabled()) {
            return false;
        }

        $normalizedProvided = $this->normalizeRecoveryCode($providedCode);
        if ($normalizedProvided === '') {
            return false;
        }

        $entry = $this->entryForIdentity($identity);
        if ($entry === null) {
            return false;
        }

        $plainMatched = false;
        $hashedMatched = false;
        $remaining = [];

        foreach ($this->configuredRecoveryCodes($entry) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (! $plainMatched && $this->normalizeRecoveryCode($candidate) === $normalizedProvided) {
                $plainMatched = true;
                continue;
            }

            if (! $hashedMatched && password_get_info($candidate)['algo'] !== null && password_verify($normalizedProvided, $candidate)) {
                $hashedMatched = true;
                continue;
            }

            $remaining[] = $candidate;
        }

        if (! $plainMatched && ! $hashedMatched) {
            return false;
        }

        return $this->updateEntry($identity, static function (array $entry) use ($remaining): array {
            $entry['recovery_codes'] = array_values($remaining);

            return $entry;
        });
    }

    private function normalizeSecondFactorMethod(?string $method): string
    {
        $normalized = strtolower(trim((string) $method));

        return match ($normalized) {
            'totp', 'otp' => 'totp',
            'recovery_code', 'backup_code', 'recovery' => 'recovery_code',
            'second_factor', 'mfa_code', 'code', '' => 'second_factor',
            default => 'second_factor',
        };
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? '');
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function totpPeriod(array $entry): int
    {
        $value = $entry['totp_period'] ?? $this->config->get('auth.mfa.totp.period', 30);

        return is_numeric($value) ? max(1, (int) $value) : 30;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function totpDigits(array $entry): int
    {
        $value = $entry['totp_digits'] ?? $this->config->get('auth.mfa.totp.digits', 6);

        return is_numeric($value) ? max(6, (int) $value) : 6;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function totpAlgorithm(array $entry): string
    {
        $value = $entry['totp_algorithm'] ?? $this->config->get('auth.mfa.totp.algorithm', 'sha1');

        return is_string($value) && trim($value) !== '' ? trim($value) : 'sha1';
    }

    private function totpWindow(): int
    {
        $value = $this->config->get('auth.mfa.totp.window', 1);

        return is_numeric($value) ? max(0, (int) $value) : 1;
    }

    private function totpEnabled(): bool
    {
        return (bool) $this->config->get('auth.mfa.totp.enabled', false);
    }

    private function recoveryCodesEnabled(): bool
    {
        return (bool) $this->config->get('auth.mfa.recovery_codes.enabled', false);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $keys
     */
    private function booleanEntryValue(array $entry, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $entry)) {
                continue;
            }

            $value = $entry[$key];

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1;
            }

            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
            }
        }

        return false;
    }
}
