<?php

declare(strict_types=1);

namespace Quantum\Console\Commands;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;
use Quantum\Auth\Devices\TrustedDevice;
use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;

final class AuthSecurityCenterRevokeDeviceCommand extends Command
{
    public function name(): string
    {
        return 'auth:security-center:revoke-device';
    }

    public function description(): string
    {
        return 'Revoca operacionalmente sesiones y trusted devices de un device agregado para una identidad concreta.';
    }

    public function usage(): string
    {
        return 'auth:security-center:revoke-device --identity=value --device-reference=value --actor-identity=value --actor-session-public-id=value [--type=value] [--actor-type=value] [--scope=all|sessions|trusted-devices] [--include-public-ids] [--audit-log=path] [--dry-run] [--json] [--verbose]';
    }

    public function category(): string
    {
        return 'Authentication';
    }

    public function optionsHelp(): array
    {
        return [
            '--identity=' => 'Identificador exacto de la identidad objetivo.',
            '--device-reference=' => 'Device reference agregado que se desea revocar.',
            '--actor-identity=' => 'Identificador exacto del actor administrativo que ejecuta la mutacion.',
            '--actor-session-public-id=' => 'Session publica activa del actor usada como prueba operativa.',
            '--type=' => 'Tipo de identidad. Default: user.',
            '--actor-type=' => 'Tipo de identidad del actor. Default: user.',
            '--scope=' => 'Alcance de la revocacion: all, sessions o trusted-devices. Default: all.',
            '--include-public-ids' => 'Incluye session_public_ids y trusted_device_public_ids en el resultado.',
            '--audit-log=' => 'Anexa un evento JSONL durable con actor, target y resultado operativo.',
            '--dry-run' => 'Calcula la revocacion sin persistir cambios.',
            '--json' => 'Emite el resultado en JSON.',
            '--verbose' => 'Muestra detalle adicional del driver, filtro y conteos.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $identity = $this->resolveRequiredStringOption($input, 'identity');
        $deviceReference = $this->resolveRequiredStringOption($input, 'device-reference');
        $actorIdentity = $this->resolveRequiredStringOption($input, 'actor-identity');
        $actorSessionPublicId = $this->resolveRequiredStringOption($input, 'actor-session-public-id');
        $type = $this->resolveType($input);
        $actorType = $this->resolveActorType($input);
        $scope = $this->resolveScope($input);
        $includePublicIds = $input->hasOption('include-public-ids');
        $auditLogPath = $this->resolveOptionalStringOption($input, 'audit-log');
        $dryRun = $input->hasOption('dry-run');
        $json = $input->hasOption('json');
        $now = $this->resolveNow($input);
        $eventTimestamp = $now ?? time();

        if ($identity === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'validation_failed',
                'reason_code' => 'missing_identity',
            ]);

            return $this->renderValidationFailure($output, $json, 'La opcion --identity es obligatoria.');
        }

        if ($deviceReference === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'validation_failed',
                'reason_code' => 'missing_device_reference',
                'target' => [
                    'identity' => $identity,
                    'type' => $type,
                ],
            ]);

            return $this->renderValidationFailure($output, $json, 'La opcion --device-reference es obligatoria.');
        }

        if ($actorIdentity === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'validation_failed',
                'reason_code' => 'missing_actor_identity',
                'target' => [
                    'identity' => $identity,
                    'type' => $type,
                    'device_reference' => $deviceReference,
                ],
            ]);

            return $this->renderValidationFailure($output, $json, 'La opcion --actor-identity es obligatoria.');
        }

        if ($actorSessionPublicId === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'validation_failed',
                'reason_code' => 'missing_actor_session_public_id',
                'target' => [
                    'identity' => $identity,
                    'type' => $type,
                    'device_reference' => $deviceReference,
                ],
                'actor' => [
                    'identity' => $actorIdentity,
                    'type' => $actorType,
                ],
            ]);

            return $this->renderValidationFailure($output, $json, 'La opcion --actor-session-public-id es obligatoria.');
        }

        if ($scope === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'validation_failed',
                'reason_code' => 'invalid_scope',
                'target' => [
                    'identity' => $identity,
                    'type' => $type,
                    'device_reference' => $deviceReference,
                ],
                'actor' => [
                    'identity' => $actorIdentity,
                    'type' => $actorType,
                    'session_public_id' => $actorSessionPublicId,
                ],
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --scope debe ser all, sessions o trusted-devices.',
            );
        }

        $app = $this->bootstrapApplication();
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $actorAuthorization = $this->authorizedActorContext(
            $sessions->all(),
            $actorIdentity,
            $actorType,
            $actorSessionPublicId,
            $now,
        );

        if ($actorAuthorization === null || ! (bool) ($actorAuthorization['authorized'] ?? false)) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'result' => 'authorization_failed',
                'reason_code' => 'unauthorized_management_actor',
                'target' => [
                    'identity' => $identity,
                    'type' => $type,
                    'device_reference' => $deviceReference,
                    'scope' => $scope,
                ],
                'actor' => [
                    'identity' => $actorIdentity,
                    'type' => $actorType,
                    'session_public_id' => $actorSessionPublicId,
                    'management_authorization_reason_code' => $actorAuthorization['authorization_reason_code'] ?? null,
                ],
            ]);

            return $this->renderAuthorizationFailure(
                $output,
                $json,
                'El actor administrativo no tiene una sesion gobernada valida para revocar dispositivos agregados.',
            );
        }

        /** @var AuthenticationContext $actorContext */
        $actorContext = $actorAuthorization['context'];
        $actorAuthorizationMode = (string) $actorAuthorization['authorization_mode'];

        $matchedSessions = $this->matchingSessions($sessions->all(), $identity, $type, $deviceReference, $now);
        $matchedTrustedDevices = $this->matchingTrustedDevices($trustedDevices->all($now), $identity, $type, $deviceReference);

        $sessionsToRevoke = $scope === 'trusted-devices' ? [] : $matchedSessions;
        $trustedDevicesToRevoke = $scope === 'sessions' ? [] : $matchedTrustedDevices;

        $payload = [
            'generated_at' => $eventTimestamp,
            'filters' => [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
                'actor_identity' => $actorIdentity,
                'actor_type' => $actorType,
                'actor_session_public_id' => $actorSessionPublicId,
                'scope' => $scope,
                'include_public_ids' => $includePublicIds,
                'dry_run' => $dryRun,
            ],
            'summary' => [
                'matched_sessions' => count($matchedSessions),
                'matched_trusted_devices' => count($matchedTrustedDevices),
                'revoked_sessions' => count($sessionsToRevoke),
                'revoked_trusted_devices' => count($trustedDevicesToRevoke),
            ],
            'actor' => [
                'identity' => $actorIdentity,
                'type' => $actorType,
                'session_public_id' => $actorSessionPublicId,
                'management_authority' => $actorContext->managementAuthority(),
                'management_ownership_proof' => $actorContext->managementOwnershipProof(),
                'management_claims_source' => $actorContext->managementClaimsSource(),
                'management_privilege_level' => $actorContext->managementPrivilegeLevel(),
                'management_authorized' => true,
                'management_authorization_mode' => $actorAuthorizationMode,
                'management_authorization_reason_code' => $actorContext->managementAuthorizationReasonCode(),
                'management_scopes' => $actorContext->managementScopes(),
                'authorized' => true,
            ],
        ];

        if ($includePublicIds) {
            $sessionPublicIds = array_values(array_filter(array_map(
                static fn (AuthenticationSession $session): ?string => $session->publicId(),
                $sessionsToRevoke,
            )));
            sort($sessionPublicIds);

            $trustedDevicePublicIds = array_values(array_map(
                static fn (TrustedDevice $device): string => $device->publicId->value,
                $trustedDevicesToRevoke,
            ));
            sort($trustedDevicePublicIds);

            $payload['detail'] = [
                'session_public_ids' => $sessionPublicIds,
                'trusted_device_public_ids' => $trustedDevicePublicIds,
            ];
        }

        if (! $dryRun) {
            foreach ($sessionsToRevoke as $session) {
                $sessions->delete($session->id->value, AuthenticationSessionRecoveryReason::Revoked);
            }

            foreach ($trustedDevicesToRevoke as $device) {
                $trustedDevices->delete($device->publicId->value);
            }
        }

        $this->writeAuditEvent($auditLogPath, [
            'event' => 'security_center_device_revocation_' . ($dryRun ? 'planned' : 'executed'),
            'occurred_at' => $eventTimestamp,
            'result' => $dryRun ? 'dry_run' : 'executed',
            'target' => [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
                'scope' => $scope,
            ],
            'actor' => [
                'identity' => $actorIdentity,
                'type' => $actorType,
                'session_public_id' => $actorSessionPublicId,
                'management_authority' => $actorContext->managementAuthority(),
                'management_ownership_proof' => $actorContext->managementOwnershipProof(),
                'management_claims_source' => $actorContext->managementClaimsSource(),
                'management_privilege_level' => $actorContext->managementPrivilegeLevel(),
                'management_authorized' => true,
                'management_authorization_mode' => $actorAuthorizationMode,
                'management_authorization_reason_code' => $actorContext->managementAuthorizationReasonCode(),
                'management_scopes' => $actorContext->managementScopes(),
            ],
            'summary' => $payload['summary'],
            'detail' => $payload['detail'] ?? null,
        ]);

        if ($json) {
            $output->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 0;
        }

        if ($input->hasOption('verbose')) {
            $sessionDriver = (string) $app->config('auth.session.driver', 'memory');
            $trustedDriver = $app->config('auth.trusted_devices.driver', $sessionDriver);
            $trustedDriver = is_string($trustedDriver) && trim($trustedDriver) !== ''
                ? trim($trustedDriver)
                : $sessionDriver;

            $output->writeln(sprintf('Driver sesiones: %s', $sessionDriver));
            $output->writeln(sprintf('Driver trusted devices: %s', $trustedDriver));
            $output->writeln(sprintf('Identity: %s:%s', $type, $identity));
            $output->writeln(sprintf('Actor: %s:%s', $actorType, $actorIdentity));
            $output->writeln(sprintf('Actor session: %s', $actorSessionPublicId));
            $output->writeln(sprintf('Device reference: %s', $deviceReference));
            $output->writeln(sprintf('Scope: %s', $scope));
            $output->writeln(sprintf('Dry run: %s', $dryRun ? 'si' : 'no'));
            $output->writeln();
        }

        $output->writeln($dryRun
            ? 'Revocacion operacional calculada correctamente (dry-run).'
            : 'Revocacion operacional ejecutada correctamente.');
        $output->writeln(sprintf('  Sesiones objetivo: %d', $payload['summary']['matched_sessions']));
        $output->writeln(sprintf('  Trusted devices objetivo: %d', $payload['summary']['matched_trusted_devices']));
        $output->writeln(sprintf('  Sesiones revocadas: %d', $payload['summary']['revoked_sessions']));
        $output->writeln(sprintf('  Trusted devices revocados: %d', $payload['summary']['revoked_trusted_devices']));
        $output->writeln(sprintf(
            '  Actor autorizado: %s:%s | authority=%s | privilege=%s | mode=%s | scopes=%s',
            $actorType,
            $actorIdentity,
            $payload['actor']['management_authority'],
            $payload['actor']['management_privilege_level'],
            $payload['actor']['management_authorization_mode'],
            implode(',', $payload['actor']['management_scopes']),
        ));

        if ($includePublicIds) {
            $sessionPublicIds = $payload['detail']['session_public_ids'] ?? [];
            $trustedPublicIds = $payload['detail']['trusted_device_public_ids'] ?? [];

            $output->writeln(sprintf(
                '  session_public_ids=%s',
                $sessionPublicIds === [] ? 'none' : implode(',', $sessionPublicIds),
            ));
            $output->writeln(sprintf(
                '  trusted_device_public_ids=%s',
                $trustedPublicIds === [] ? 'none' : implode(',', $trustedPublicIds),
            ));
        }

        return 0;
    }

    private function resolveNow(Input $input): ?int
    {
        $option = $input->option('now');

        if (is_string($option) && trim($option) !== '' && is_numeric($option)) {
            return (int) $option;
        }

        return null;
    }

    private function resolveRequiredStringOption(Input $input, string $key): ?string
    {
        $option = $input->option($key);

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : null;
    }

    private function resolveOptionalStringOption(Input $input, string $key): ?string
    {
        return $this->resolveRequiredStringOption($input, $key);
    }

    private function resolveType(Input $input): string
    {
        $type = $input->option('type');

        return is_string($type) && trim($type) !== ''
            ? trim($type)
            : 'user';
    }

    private function resolveActorType(Input $input): string
    {
        $type = $input->option('actor-type');

        return is_string($type) && trim($type) !== ''
            ? trim($type)
            : 'user';
    }

    private function resolveScope(Input $input): ?string
    {
        $scope = $input->option('scope');

        if (! is_string($scope) || trim($scope) === '') {
            return 'all';
        }

        $normalized = trim($scope);

        return in_array($normalized, ['all', 'sessions', 'trusted-devices'], true)
            ? $normalized
            : null;
    }

    private function renderValidationFailure(Output $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode([
                'error' => $message,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $output->error($message);

        return 1;
    }

    private function renderAuthorizationFailure(Output $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode([
                'error' => $message,
                'reason_code' => 'unauthorized_management_actor',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $output->error($message);

        return 1;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function writeAuditEvent(?string $path, array $event): void
    {
        if ($path === null) {
            return;
        }

        $directory = dirname($path);

        if ($directory !== '' && $directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            (string) json_encode($event, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND,
        );
    }

    /**
     * @param list<AuthenticationSession> $sessions
     * @return list<AuthenticationSession>
     */
    private function matchingSessions(
        array $sessions,
        string $identity,
        string $type,
        string $deviceReference,
        ?int $now,
    ): array {
        return array_values(array_filter(
            $sessions,
            static function (AuthenticationSession $session) use ($identity, $type, $deviceReference, $now): bool {
                if ($session->isExpired($now)) {
                    return false;
                }

                $sessionDeviceReference = $session->attributes['session_device_reference'] ?? null;

                return $session->reference->type === $type
                    && $session->reference->identifier->value === $identity
                    && is_string($sessionDeviceReference)
                    && trim($sessionDeviceReference) === $deviceReference;
            },
        ));
    }

    /**
     * @param list<TrustedDevice> $devices
     * @return list<TrustedDevice>
     */
    private function matchingTrustedDevices(
        array $devices,
        string $identity,
        string $type,
        string $deviceReference,
    ): array {
        return array_values(array_filter(
            $devices,
            static fn (TrustedDevice $device): bool => $device->reference->type === $type
                && $device->reference->identifier->value === $identity
                && $device->deviceReference === $deviceReference,
        ));
    }

    /**
     * @param list<AuthenticationSession> $sessions
     */
    private function authorizedActorContext(
        array $sessions,
        string $actorIdentity,
        string $actorType,
        string $actorSessionPublicId,
        ?int $now,
    ): ?array {
        foreach ($sessions as $session) {
            if ($session->isExpired($now)) {
                continue;
            }

            if ($session->reference->type !== $actorType) {
                continue;
            }

            if ($session->reference->identifier->value !== $actorIdentity) {
                continue;
            }

            if ($session->publicId() !== $actorSessionPublicId) {
                continue;
            }

            $context = new AuthenticationContext(
                identity: $session->identity,
                reference: $session->reference,
                requestId: 'security-center-revoke-device',
                method: $session->method,
                attributes: $session->attributes,
            );

            return [
                'context' => $context,
                'authorized' => $context->canAdministrativelyManageDevices(),
                'authorization_mode' => $context->managementAuthorizationMode(),
                'authorization_reason_code' => $context->managementAuthorizationReasonCode(),
            ];
        }

        return null;
    }
}
