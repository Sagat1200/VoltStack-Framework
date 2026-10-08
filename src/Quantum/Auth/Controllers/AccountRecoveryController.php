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
            attributes: $this->requestMetadata($request),
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
            'completed_at' => $result->completedAt,
            'metadata' => $result->metadata,
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Auth-Recovery-Purpose' => $result->purpose->value,
            'X-Auth-Recovery-Status' => 'completed',
        ]);
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
