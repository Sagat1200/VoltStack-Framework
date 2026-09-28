<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Passkeys\AssertionResult;
use Quantum\Auth\Passkeys\InMemoryPasskeyCredentialStore;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Passkeys\PasskeyCredentialRecord;
use Quantum\Auth\Passkeys\PasskeyRegistrationCeremony;
use Quantum\Auth\Passkeys\RelyingPartyConfig;

final class BloqueFTest extends TestCase
{
    public function test_relying_party_config_from_array_applies_defaults_and_overrides(): void
    {
        $custom = RelyingPartyConfig::fromArray([
            'rp_id' => 'app.example.com',
            'rp_name' => 'Acme Example App',
            'origins' => ['https://app.example.com', 'https://m.example.com'],
        ]);

        self::assertSame('app.example.com', $custom->rpId);
        self::assertSame('Acme Example App', $custom->rpName);
        self::assertCount(2, $custom->allowedOrigins);

        $defaults = RelyingPartyConfig::fromArray([]);
        self::assertSame('localhost', $defaults->rpId);
        self::assertSame('VoltStack Local', $defaults->rpName);
    }

    public function test_credential_record_hydration_roundtrip_to_array(): void
    {
        $record = new PasskeyCredentialRecord(
            credentialId: 'cred_abc123',
            credentialPublicKey: '-----BEGIN PUBLIC KEY----- mock',
            userHandle: 'usr_001',
            rpId: 'example.com',
            signCount: 4,
            createdAt: 1700000000,
            transports: ['usb', 'nfc', 'internal'],
        );

        $arr = $record->toArray();
        $hydrated = PasskeyCredentialRecord::fromArray($arr);

        self::assertSame($record->credentialId, $hydrated->credentialId);
        self::assertSame($record->credentialPublicKey, $hydrated->credentialPublicKey);
        self::assertSame($record->userHandle, $hydrated->userHandle);
        self::assertSame($record->rpId, $hydrated->rpId);
        self::assertSame(4, $hydrated->signCount);
        self::assertSame(1700000000, $hydrated->createdAt);
        self::assertSame($record->transports, $hydrated->transports);
    }

    public function test_in_memory_store_save_and_find_by_credential_id_and_revoke(): void
    {
        $store = new InMemoryPasskeyCredentialStore();
        $record = PasskeyCredentialRecord::fromArray([
            'credential_id' => 'cred_saved_01',
            'credential_public_key' => 'mock-key',
            'user_handle' => 'usr_X',
            'rp_id' => 'localhost',
            'sign_count' => 0,
        ]);

        self::assertNull($store->findByCredentialId('cred_saved_01'));
        $store->save($record);
        $found = $store->findByCredentialId('cred_saved_01');
        self::assertNotNull($found);
        self::assertSame('usr_X', $found->userHandle);

        $listed = $store->listForUserHandle('usr_X');
        self::assertCount(1, $listed);

        $revoked = $store->revoke('cred_saved_01');
        self::assertTrue($revoked);
        self::assertNull($store->findByCredentialId('cred_saved_01'));
        self::assertFalse($store->revoke('cred_saved_01'));
    }

    public function test_registration_begin_returns_challenge_payload_of_64_hex_chars(): void
    {
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'vault.test', 'rp_name' => 'Vault']);
        $ceremony = new PasskeyRegistrationCeremony($rp);

        $begin = $ceremony->beginRegistration(userHandle: 'usr_mock', userName: 'mock@vault.test', displayName: 'Mock User');
        self::assertIsString($begin['challenge']);
        self::assertSame(64, strlen($begin['challenge']));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $begin['challenge']);
        self::assertSame('vault.test', $begin['rp']['id']);
        self::assertSame('usr_mock', $begin['user']['id']);
    }

    public function test_assertion_begin_emits_challenge_payload_with_rp_id(): void
    {
        $rp = RelyingPartyConfig::fromArray(['rp_id' => 'vault.test']);
        $ceremony = new PasskeyAssertionCeremony($rp);

        $begin = $ceremony->beginAssertion(userHandle: 'usr_A');
        self::assertSame(64, strlen($begin['challenge']));
        self::assertSame('vault.test', $begin['rp_id']);
        self::assertSame('usr_A', $begin['user_handle']);
    }

    public function test_assertion_finish_simulated_passes_for_valid_credential_id(): void
    {
        $rp = RelyingPartyConfig::fromArray([]);
        $ceremony = new PasskeyAssertionCeremony($rp);

        $valid = $ceremony->finishAssertion([
            'credential_id' => 'cred_assert_01',
            'user_handle' => 'usr_Y',
            'previous_sign_count' => 3,
        ]);
        self::assertTrue($valid->isValid);
        self::assertSame('cred_assert_01', $valid->credentialId);
        self::assertSame('usr_Y', $valid->userHandle);
        self::assertSame(4, $valid->signCountIncremented);
        self::assertArrayHasKey('skeleton_version', $valid->metadata);
        self::assertTrue($valid->metadata['simulated'] ?? false);

        $invalid = $ceremony->finishAssertion([]);
        self::assertFalse($invalid->isValid);
        self::assertSame(0, $invalid->signCountIncremented);
    }
}
