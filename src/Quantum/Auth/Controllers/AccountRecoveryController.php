<?php

declare(strict_types=1);

namespace Quantum\Auth\Controllers;

use Quantum\Auth\Contracts\RecoveryManagerInterface;
use Quantum\Auth\Recovery\RecoveryContinuationRequest;
use Quantum\Auth\Recovery\RecoveryPurpose;
use Quantum\Auth\Recovery\RecoveryRequest;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Controllers\Controller;

final class AccountRecoveryController extends Controller
{
    public function __construct(
        private readonly RecoveryManagerInterface $recovery,
    ) {}

    public function requestPasswordReset(Request $request): Response
    {
        $result = $this->recovery->begin(new RecoveryRequest(
            purpose: RecoveryPurpose::PasswordReset,
            identifier: $this->stringInput($request, ['identifier', 'email', 'username']),
            transport: 'http',
            attributes: $this->requestMetadata($request),
        ));

        $payload = [
            'endpoint' => $request->path(),
            'status' => 'accepted',
            'purpose' => $result->purpose->value,
            'issued' => $result->issued,
            'delivery' => $result->delivery,
            'expires_at' => $result->expiresAt,
            'token' => $result->token,
            'metadata' => $result->metadata,
        ];

        return $this->json($payload, 202, [
            'Cache-Control' => 'no-store',
            'X-Auth-Recovery-Purpose' => $result->purpose->value,
            'X-Auth-Recovery-Status' => 'accepted',
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        $result = $this->recovery->continueRecovery(new RecoveryContinuationRequest(
            purpose: RecoveryPurpose::PasswordReset,
            token: $this->stringInput($request, ['token']),
            newPassword: $this->stringInput($request, ['new_password', 'password']),
            transport: 'http',
            attributes: array_merge(
                $this->requestMetadata($request),
                [
                    'recovery_evidences' => $this->collectRecoveryEvidences($request),
                ],
            ),
        ));

        return $this->json([
            'endpoint' => $request->path(),
            'status' => 'completed',
            'purpose' => $result->purpose->value,
            'identity' => [
                'type' => $result->identityType,
                'id' => $result->identityId,
            ],
            'sessions_revoked' => $result->sessionsRevoked,
            'tokens_revoked' => $result->tokensRevoked,
            'trusted_devices_revoked' => $result->trustedDevicesRevoked,
            'passkeys_revoked' => $result->passkeysRevoked,
            'completed_at' => $result->completedAt,
            'metadata' => $result->metadata,
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Auth-Recovery-Purpose' => $result->purpose->value,
            'X-Auth-Recovery-Status' => 'completed',
        ]);
    }

    /**
     * Collect recovery proofs from the HTTP request.
     *
     * Accepts either:
     *   - a `recovery_evidences` JSON array body,
     *   - scalar inputs via `recovery_evidence_kind[]`, `recovery_evidence_value[]`,
     *     and optionally `recovery_evidence_metadata[<index>][...]` for simple
     *     forms,
     *   - a single `recovery_evidence_kind` + `recovery_evidence_value` pair
     *     (expanded to a one-element list).
     *
     * @return list<array{kind:string,value:string,metadata:array<string,mixed>}>
     */
    private function collectRecoveryEvidences(Request $request): array
    {
        $json = $request->input('recovery_evidences');
        if (is_array($json) && $json !== []) {
            $out = [];
            foreach ($json as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $kind = is_string($raw['kind'] ?? null) ? trim((string) $raw['kind']) : '';
                $value = is_string($raw['value'] ?? null) ? trim((string) $raw['value']) : '';
                $metadata = isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : [];

                if ($kind === '' || $value === '') {
                    continue;
                }

                $out[] = [
                    'kind' => $kind,
                    'value' => $value,
                    'metadata' => $metadata,
                ];
            }

            return $out;
        }

        $kinds = $request->input('recovery_evidence_kind');
        $values = $request->input('recovery_evidence_value');
        $metadatas = $request->input('recovery_evidence_metadata');

        if (is_array($kinds) && is_array($values)) {
            $out = [];
            foreach (array_keys($kinds) as $i) {
                $kind = is_string($kinds[$i] ?? null) ? trim((string) $kinds[$i]) : '';
                $value = is_string($values[$i] ?? null) ? trim((string) $values[$i]) : '';
                if ($kind === '' || $value === '') {
                    continue;
                }

                $metadata = isset($metadatas[$i]) && is_array($metadatas[$i]) ? $metadatas[$i] : [];

                $out[] = [
                    'kind' => $kind,
                    'value' => $value,
                    'metadata' => $metadata,
                ];
            }

            return $out;
        }

        $singleKind = is_string($request->input('recovery_evidence_kind')) ? trim((string) $request->input('recovery_evidence_kind')) : '';
        $singleValue = is_string($request->input('recovery_evidence_value')) ? trim((string) $request->input('recovery_evidence_value')) : '';

        if ($singleKind !== '' && $singleValue !== '') {
            return [
                [
                    'kind' => $singleKind,
                    'value' => $singleValue,
                    'metadata' => [],
                ],
            ];
        }

        return [];
    }

    /**
     * @param list<string> $keys
     */
    private function stringInput(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMetadata(Request $request): array
    {
        return array_filter([
            'ip' => $request->server('remote_addr'),
            'user_agent' => $request->header('User-Agent'),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }
}
