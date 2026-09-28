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
use VoltStack\Framework\Application;

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
        return 'auth:security-center:revoke-device --identity=value --device-reference=value --actor-identity=value --actor-session-public-id=value [--type=value] [--actor-type=value] [--scope=all|sessions|trusted-devices] [--include-public-ids] [--correlation-id=value] [--audit-log=path] [--audit-log-source=path] [--dry-run] [--json] [--verbose]';
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
            '--correlation-id=' => 'Usa un correlation id explicito para enlazar auditorias y mutaciones relacionadas.',
            '--audit-log=' => 'Anexa un evento JSONL durable con actor, target y resultado operativo.',
            '--audit-log-source=' => 'Lee un audit log JSONL durable para evaluar la guardia distribuida previa a la mutacion remota.',
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
        $auditLogSource = $this->resolveOptionalStringOption($input, 'audit-log-source');
        $dryRun = $input->hasOption('dry-run');
        $json = $input->hasOption('json');
        $now = $this->resolveNow($input);
        $eventTimestamp = $now ?? time();
        $correlationId = $this->resolveCorrelationId($input, 'security-center-revoke-device');
        $operationId = $this->createOperationId('security-center-revoke-device');

        $defaultRejectionResourceCoverage = static function (string $scope): array {
            $targeted = match ($scope) {
                'sessions' => ['sessions'],
                'trusted-devices' => ['trusted-devices'],
                default => ['sessions', 'trusted-devices'],
            };

            return [
                'targeted_resource_kinds' => $targeted,
                'matched_resources' => [
                    'sessions' => 0,
                    'trusted-devices' => 0,
                    'total' => 0,
                ],
                'affected_resources' => [
                    'sessions' => 0,
                    'trusted-devices' => 0,
                    'total' => 0,
                ],
                'affected_resource_kinds' => [],
                'missing_targeted_resource_kinds' => $targeted,
                'has_partial_affected_resource_coverage' => false,
                'has_any_affected_resources' => false,
                'target_store_fingerprints' => [],
                'target_store_statuses' => [],
                'degraded_target_store_fingerprints' => [],
                'has_degraded_target_stores' => false,
            ];
        };

        if ($identity === null) {
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'validation_failed',
                'reason_code' => 'missing_identity',
                'resource_coverage' => $defaultRejectionResourceCoverage('all'),
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --identity es obligatoria.',
                'missing_identity',
                $correlationId,
                $operationId,
                'all',
            );
        }

        if ($deviceReference === null) {
            $target = [
                'identity' => $identity,
                'type' => $type,
            ];
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'validation_failed',
                'reason_code' => 'missing_device_reference',
                'target' => $target,
                'resource_coverage' => $defaultRejectionResourceCoverage('all'),
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --device-reference es obligatoria.',
                'missing_device_reference',
                $correlationId,
                $operationId,
                'all',
                $target,
            );
        }

        if ($actorIdentity === null) {
            $target = [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
            ];
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'validation_failed',
                'reason_code' => 'missing_actor_identity',
                'target' => $target,
                'resource_coverage' => $defaultRejectionResourceCoverage('all'),
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --actor-identity es obligatoria.',
                'missing_actor_identity',
                $correlationId,
                $operationId,
                'all',
                $target,
            );
        }

        if ($actorSessionPublicId === null) {
            $target = [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
            ];
            $actor = [
                'identity' => $actorIdentity,
                'type' => $actorType,
            ];
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'validation_failed',
                'reason_code' => 'missing_actor_session_public_id',
                'target' => $target,
                'actor' => $actor,
                'resource_coverage' => $defaultRejectionResourceCoverage('all'),
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --actor-session-public-id es obligatoria.',
                'missing_actor_session_public_id',
                $correlationId,
                $operationId,
                'all',
                $target,
                $actor,
            );
        }

        if ($scope === null) {
            $target = [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
            ];
            $actor = [
                'identity' => $actorIdentity,
                'type' => $actorType,
                'session_public_id' => $actorSessionPublicId,
            ];
            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'validation_failed',
                'reason_code' => 'invalid_scope',
                'target' => $target,
                'actor' => $actor,
                'resource_coverage' => $defaultRejectionResourceCoverage('all'),
            ]);

            return $this->renderValidationFailure(
                $output,
                $json,
                'La opcion --scope debe ser all, sessions o trusted-devices.',
                'invalid_scope',
                $correlationId,
                $operationId,
                'all',
                $target,
                $actor,
            );
        }

        $app = $this->bootstrapApplication();
        $sessions = $app->make(AuthenticationSessionRepositoryInterface::class);
        $trustedDevices = $app->make(TrustedDeviceRepositoryInterface::class);
        $operationalContext = $this->operationalContext($app);
        $actorAuthorization = $this->authorizedActorContext(
            $sessions->all(),
            $actorIdentity,
            $actorType,
            $actorSessionPublicId,
            $now,
            $scope,
        );

        if ($actorAuthorization === null || ! (bool) ($actorAuthorization['authorized'] ?? false)) {
            $administrativeMetrics = $this->administrativeMetrics(
                scope: $scope,
                dryRun: $dryRun,
                authorizationOutcome: 'authorization_failed',
                authorizationMode: is_string($actorAuthorization['authorization_mode'] ?? null)
                    ? $actorAuthorization['authorization_mode']
                    : null,
                actorPrivilegeLevel: null,
                actorScopes: [],
                matchedSessions: 0,
                matchedTrustedDevices: 0,
                affectedSessions: 0,
                affectedTrustedDevices: 0,
            );

            $rejectionCoverage = $defaultRejectionResourceCoverage($scope);
            $target = [
                'identity' => $identity,
                'type' => $type,
                'device_reference' => $deviceReference,
                'scope' => $scope,
            ];
            $actor = [
                'identity' => $actorIdentity,
                'type' => $actorType,
                'session_public_id' => $actorSessionPublicId,
                'management_authorization_reason_code' => $actorAuthorization['authorization_reason_code'] ?? null,
            ];

            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'authorization_failed',
                'reason_code' => 'unauthorized_management_actor',
                'target' => $target,
                'actor' => $actor,
                'operational_context' => $operationalContext,
                'administrative_metrics' => $administrativeMetrics,
                'resource_coverage' => $rejectionCoverage,
            ]);

            return $this->renderAuthorizationFailure(
                $output,
                $json,
                'El actor administrativo no tiene una sesion gobernada valida para revocar dispositivos agregados.',
                'unauthorized_management_actor',
                $correlationId,
                $operationId,
                $scope,
                $target,
                $actor,
                $operationalContext,
                $administrativeMetrics,
            );
        }

        /** @var AuthenticationContext $actorContext */
        $actorContext = $actorAuthorization['context'];
        $actorAuthorizationMode = (string) $actorAuthorization['authorization_mode'];

        $matchedSessions = $this->matchingSessions($sessions->all(), $identity, $type, $deviceReference, $now);
        $matchedTrustedDevices = $this->matchingTrustedDevices($trustedDevices->all($now), $identity, $type, $deviceReference);

        $sessionsToRevoke = $scope === 'trusted-devices' ? [] : $matchedSessions;
        $trustedDevicesToRevoke = $scope === 'sessions' ? [] : $matchedTrustedDevices;
        $distributedGuard = $this->distributedGuard($auditLogSource);
        $actorTargetRelation = $actorContext->managementActorTargetRelation($identity, $type);
        $actorTargetReasonCode = $actorContext->managementActorTargetReasonCode($identity, $type);
        $actorTargetScopeRelation = $actorContext->managementActorTargetScopeRelation($identity, $type, $scope);
        $actorTargetScopeReasonCode = $actorContext->managementActorTargetScopeReasonCode($identity, $type, $scope);
        $distributedGuardScopeDecision = $this->distributedGuardScopeDecision(
            $scope,
            $distributedGuard,
            $actorAuthorizationMode,
            $actorContext->managementPrivilegeLevel(),
            $actorTargetRelation,
            $actorTargetReasonCode,
            $actorTargetScopeRelation,
            $actorTargetScopeReasonCode,
        );
        $administrativeMetrics = $this->administrativeMetrics(
            scope: $scope,
            dryRun: $dryRun,
            authorizationOutcome: 'authorized',
            authorizationMode: $actorAuthorizationMode,
            actorPrivilegeLevel: $actorContext->managementPrivilegeLevel(),
            actorScopes: $actorContext->managementScopes(),
            matchedSessions: count($matchedSessions),
            matchedTrustedDevices: count($matchedTrustedDevices),
            affectedSessions: count($sessionsToRevoke),
            affectedTrustedDevices: count($trustedDevicesToRevoke),
        );
        $resourceCoverage = $this->resourceCoverage(
            scope: $scope,
            distributedGuard: $distributedGuard,
            matchedSessions: count($matchedSessions),
            matchedTrustedDevices: count($matchedTrustedDevices),
            affectedSessions: count($sessionsToRevoke),
            affectedTrustedDevices: count($trustedDevicesToRevoke),
        );

        if (
            ! $dryRun
            && (bool) ($distributedGuardScopeDecision['should_deny'] ?? false)
        ) {
            $guardedAdministrativeMetrics = $this->administrativeMetrics(
                scope: $scope,
                dryRun: $dryRun,
                authorizationOutcome: 'distributed_guard_denied',
                authorizationMode: $actorAuthorizationMode,
                actorPrivilegeLevel: $actorContext->managementPrivilegeLevel(),
                actorScopes: $actorContext->managementScopes(),
                matchedSessions: count($matchedSessions),
                matchedTrustedDevices: count($matchedTrustedDevices),
                affectedSessions: count($sessionsToRevoke),
                affectedTrustedDevices: count($trustedDevicesToRevoke),
            );
            $guardDeniedResourceCoverage = $this->resourceCoverage(
                scope: $scope,
                distributedGuard: $distributedGuard,
                matchedSessions: count($matchedSessions),
                matchedTrustedDevices: count($matchedTrustedDevices),
                affectedSessions: 0,
                affectedTrustedDevices: 0,
            );

            $denialReasonCode = is_string($distributedGuardScopeDecision['reason_code'] ?? null)
                ? $distributedGuardScopeDecision['reason_code']
                : 'distributed_remote_mutation_guard';

            $this->writeAuditEvent($auditLogPath, [
                'event' => 'security_center_device_revocation_rejected',
                'occurred_at' => $eventTimestamp,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'result' => 'distributed_guard_denied',
                'reason_code' => $denialReasonCode,
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
                    'management_actor_target_relation' => $actorTargetRelation,
                    'management_actor_target_reason_code' => $actorTargetReasonCode,
                    'management_actor_target_scope_relation' => $actorTargetScopeRelation,
                    'management_actor_target_scope_reason_code' => $actorTargetScopeReasonCode,
                    'management_scopes' => $actorContext->managementScopes(),
                ],
                'operational_context' => $operationalContext,
                'administrative_metrics' => $guardedAdministrativeMetrics,
                'distributed_guard' => $distributedGuard,
                'distributed_guard_scope_decision' => $distributedGuardScopeDecision,
                'resource_coverage' => $guardDeniedResourceCoverage,
            ]);

            return $this->renderDistributedGuardFailure(
                $output,
                $json,
                'La mutacion remota fue bloqueada por la guardia distribuida del security center.',
                $denialReasonCode,
                $distributedGuard,
                $distributedGuardScopeDecision,
                $guardDeniedResourceCoverage,
            );
        }

        $payload = [
            'generated_at' => $eventTimestamp,
            'correlation_id' => $correlationId,
            'operation_id' => $operationId,
            'operational_context' => $operationalContext,
            'distributed_guard' => $distributedGuard,
            'distributed_guard_scope_decision' => $distributedGuardScopeDecision,
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
                'audit_log_source' => $auditLogSource,
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
                'management_actor_target_relation' => $actorTargetRelation,
                'management_actor_target_reason_code' => $actorTargetReasonCode,
                'management_actor_target_scope_relation' => $actorTargetScopeRelation,
                'management_actor_target_scope_reason_code' => $actorTargetScopeReasonCode,
                'management_scopes' => $actorContext->managementScopes(),
                'authorized' => true,
            ],
            'administrative_metrics' => $administrativeMetrics,
            'resource_coverage' => $resourceCoverage,
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
            'correlation_id' => $correlationId,
            'operation_id' => $operationId,
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
                'management_actor_target_relation' => $actorTargetRelation,
                'management_actor_target_reason_code' => $actorTargetReasonCode,
                'management_actor_target_scope_relation' => $actorTargetScopeRelation,
                'management_actor_target_scope_reason_code' => $actorTargetScopeReasonCode,
                'management_scopes' => $actorContext->managementScopes(),
            ],
            'operational_context' => $operationalContext,
            'administrative_metrics' => $administrativeMetrics,
            'distributed_guard' => $distributedGuard,
            'distributed_guard_scope_decision' => $distributedGuardScopeDecision,
            'resource_coverage' => $resourceCoverage,
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
            $output->writeln(sprintf('Correlation id: %s', $correlationId));
            $output->writeln(sprintf('Operation id: %s', $operationId));
            $output->writeln(sprintf(
                'Metricas administrativas: outcome=%s profile=%s matched=%d affected=%d',
                $administrativeMetrics['authorization_outcome'],
                $administrativeMetrics['actor_scope_profile'],
                $administrativeMetrics['matched_total_resources'],
                $administrativeMetrics['affected_total_resources'],
            ));
            $output->writeln(sprintf(
                'Topology: %s | fingerprint=%s',
                $operationalContext['store_topology'],
                $operationalContext['store_fingerprint'],
            ));
            $output->writeln(sprintf(
                'Session store: %s | Trusted device store: %s',
                $operationalContext['session_store_path'] ?? 'n/a',
                $operationalContext['trusted_device_store_path'] ?? 'n/a',
            ));
            if ((bool) ($distributedGuard['evaluated'] ?? false)) {
                $operationalResponse = is_array($distributedGuard['operational_response'] ?? null)
                    ? $distributedGuard['operational_response']
                    : [];
                $output->writeln(sprintf(
                'Guardia distribuida: response=%s | deny_remote=%s | reason=%s | next_step=%s | scope_policy=%s | actor_mode=%s | privilege=%s | relation=%s | scope_relation=%s | mutation=%s | scope_decision=%s',
                    $operationalResponse['response_mode'] ?? 'normal_operations',
                    ($operationalResponse['should_deny_remote_mutations'] ?? false) ? 'si' : 'no',
                    $operationalResponse['remote_mutation_denial_reason_code'] ?? 'none',
                    $operationalResponse['next_step'] ?? 'continue_normal_operations',
                    $operationalResponse['remote_mutation_scope_policy'] ?? 'allow_all',
                    $distributedGuardScopeDecision['authorization_mode'] ?? 'none',
                $distributedGuardScopeDecision['actor_privilege_level'] ?? 'self_service',
                $distributedGuardScopeDecision['actor_target_relation'] ?? 'self',
                $distributedGuardScopeDecision['actor_target_scope_relation'] ?? 'self_service_current_identity_target',
                    $distributedGuardScopeDecision['mutation_kind'] ?? 'aggregated_device_revocation',
                    ($distributedGuardScopeDecision['should_deny'] ?? false)
                        ? 'denied:' . ($distributedGuardScopeDecision['reason_code'] ?? 'distributed_remote_mutation_guard')
                        : 'allowed',
                ));
            }
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

    private function resolveCorrelationId(Input $input, string $prefix): string
    {
        $provided = $this->resolveOptionalStringOption($input, 'correlation-id');

        if ($provided !== null) {
            return $provided;
        }

        return $prefix . '-' . bin2hex(random_bytes(8));
    }

    private function createOperationId(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8));
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

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $operationalContext
     * @param array<string, mixed>|null $administrativeMetrics
     */
    private function renderValidationFailure(
        Output $output,
        bool $json,
        string $message,
        string $reasonCode,
        string $correlationId,
        string $operationId,
        ?string $scope = null,
        array $target = [],
        array $actor = [],
        ?array $operationalContext = null,
        ?array $administrativeMetrics = null,
    ): int {
        $effectiveScope = is_string($scope) && trim($scope) !== '' ? trim($scope) : 'all';
        $targetedResourceKinds = $this->targetedResourceKindsForScope($effectiveScope);
        $resourceCoverage = [
            'targeted_resource_kinds' => $targetedResourceKinds,
            'matched_resources' => [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => 0,
            ],
            'affected_resources' => [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => 0,
            ],
            'affected_resource_kinds' => [],
            'missing_targeted_resource_kinds' => $targetedResourceKinds,
            'has_partial_affected_resource_coverage' => false,
            'has_any_affected_resources' => false,
            'target_store_fingerprints' => [],
            'target_store_statuses' => [],
            'degraded_target_store_fingerprints' => [],
            'has_degraded_target_stores' => false,
        ];

        if ($json) {
            $payload = [
                'error' => $message,
                'result' => 'validation_failed',
                'reason_code' => $reasonCode,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'resource_coverage' => $resourceCoverage,
            ];

            if ($target !== []) {
                $payload['target'] = $target;
            }

            if ($actor !== []) {
                $payload['actor'] = $actor;
            }

            if ($operationalContext !== null) {
                $payload['operational_context'] = $operationalContext;
            }

            if ($administrativeMetrics !== null) {
                $payload['administrative_metrics'] = $administrativeMetrics;
            }

            $output->writeln((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $output->error($message);

        return 1;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $operationalContext
     * @param array<string, mixed> $administrativeMetrics
     */
    private function renderAuthorizationFailure(
        Output $output,
        bool $json,
        string $message,
        string $reasonCode,
        string $correlationId,
        string $operationId,
        string $scope,
        array $target,
        array $actor,
        array $operationalContext,
        array $administrativeMetrics,
    ): int {
        $targetedResourceKinds = $this->targetedResourceKindsForScope($scope);
        $matchedTotal = (int) ($administrativeMetrics['matched_total_resources'] ?? 0);
        $resourceCoverage = [
            'targeted_resource_kinds' => $targetedResourceKinds,
            'matched_resources' => [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => $matchedTotal,
            ],
            'affected_resources' => [
                'sessions' => 0,
                'trusted-devices' => 0,
                'total' => 0,
            ],
            'affected_resource_kinds' => [],
            'missing_targeted_resource_kinds' => $targetedResourceKinds,
            'has_partial_affected_resource_coverage' => false,
            'has_any_affected_resources' => false,
            'target_store_fingerprints' => [],
            'target_store_statuses' => [],
            'degraded_target_store_fingerprints' => [],
            'has_degraded_target_stores' => false,
        ];

        if ($json) {
            $output->writeln((string) json_encode([
                'error' => $message,
                'result' => 'authorization_failed',
                'reason_code' => $reasonCode,
                'correlation_id' => $correlationId,
                'operation_id' => $operationId,
                'target' => $target,
                'actor' => $actor,
                'operational_context' => $operationalContext,
                'administrative_metrics' => $administrativeMetrics,
                'resource_coverage' => $resourceCoverage,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $output->error($message);

        return 1;
    }

    /**
     * @param array<string, mixed> $distributedGuard
     */
    private function renderDistributedGuardFailure(
        Output $output,
        bool $json,
        string $message,
        string $reasonCode,
        array $distributedGuard,
        array $distributedGuardScopeDecision,
        array $resourceCoverage,
    ): int {
        if ($json) {
            $output->writeln((string) json_encode([
                'error' => $message,
                'reason_code' => $reasonCode,
                'distributed_guard' => $distributedGuard,
                'distributed_guard_scope_decision' => $distributedGuardScopeDecision,
                'resource_coverage' => $resourceCoverage,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return 1;
        }

        $output->error($message);
        $output->writeln(sprintf('Reason code: %s', $reasonCode));

        return 1;
    }

    /**
     * @param array<string, mixed> $distributedGuard
     * @return array{
     *   targeted_resource_kinds:list<string>,
     *   matched_resources:array{sessions:int, trusted-devices:int, total:int},
     *   affected_resources:array{sessions:int, trusted-devices:int, total:int},
     *   affected_resource_kinds:list<string>,
     *   missing_targeted_resource_kinds:list<string>,
     *   has_partial_affected_resource_coverage:bool,
     *   has_any_affected_resources:bool,
     *   target_store_fingerprints:list<string>,
     *   target_store_statuses:array<string, int>,
     *   degraded_target_store_fingerprints:list<string>,
     *   has_degraded_target_stores:bool
     * }
     */
    private function resourceCoverage(
        string $scope,
        array $distributedGuard,
        int $matchedSessions,
        int $matchedTrustedDevices,
        int $affectedSessions,
        int $affectedTrustedDevices,
    ): array {
        $targetedResourceKinds = $this->targetedResourceKindsForScope($scope);
        $affectedResourceKinds = [];

        if ($affectedSessions > 0) {
            $affectedResourceKinds[] = 'sessions';
        }

        if ($affectedTrustedDevices > 0) {
            $affectedResourceKinds[] = 'trusted-devices';
        }

        $targetStoreFingerprints = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            (array) ($distributedGuard['operational_response']['target_store_fingerprints'] ?? []),
        ), static fn (string $value): bool => $value !== ''));
        sort($targetStoreFingerprints);

        $targetStoreAssessments = array_values(array_filter(
            (array) ($distributedGuard['operational_response']['target_store_assessments'] ?? []),
            static fn (mixed $value): bool => is_array($value),
        ));
        $targetStoreStatuses = [];
        $degradedTargetStoreFingerprints = [];

        foreach ($targetStoreAssessments as $assessment) {
            $status = $this->normalizedString($assessment['status'] ?? null, 'unknown');
            $targetStoreStatuses[$status] = ($targetStoreStatuses[$status] ?? 0) + 1;

            $fingerprint = $this->normalizedString($assessment['store_fingerprint'] ?? null, '');
            if ($fingerprint !== '' && $status !== 'healthy') {
                $degradedTargetStoreFingerprints[$fingerprint] = true;
            }
        }

        ksort($targetStoreStatuses);
        $degradedFingerprints = array_values(array_map('strval', array_keys($degradedTargetStoreFingerprints)));
        sort($degradedFingerprints);
        $missingTargetedResourceKinds = array_values(array_diff($targetedResourceKinds, $affectedResourceKinds));

        return [
            'targeted_resource_kinds' => $targetedResourceKinds,
            'matched_resources' => [
                'sessions' => $matchedSessions,
                'trusted-devices' => $matchedTrustedDevices,
                'total' => $matchedSessions + $matchedTrustedDevices,
            ],
            'affected_resources' => [
                'sessions' => $affectedSessions,
                'trusted-devices' => $affectedTrustedDevices,
                'total' => $affectedSessions + $affectedTrustedDevices,
            ],
            'affected_resource_kinds' => $affectedResourceKinds,
            'missing_targeted_resource_kinds' => $missingTargetedResourceKinds,
            'has_partial_affected_resource_coverage' => $affectedResourceKinds !== [] && $missingTargetedResourceKinds !== [],
            'has_any_affected_resources' => ($affectedSessions + $affectedTrustedDevices) > 0,
            'target_store_fingerprints' => $targetStoreFingerprints,
            'target_store_statuses' => $targetStoreStatuses,
            'degraded_target_store_fingerprints' => $degradedFingerprints,
            'has_degraded_target_stores' => $degradedFingerprints !== [],
        ];
    }

    /**
     * @return list<string>
     */
    private function targetedResourceKindsForScope(string $scope): array
    {
        return match ($scope) {
            'sessions' => ['sessions'],
            'trusted-devices' => ['trusted-devices'],
            default => ['sessions', 'trusted-devices'],
        };
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
     * @return array{
     *   app_name: string,
     *   app_env: string,
     *   session_driver: string,
     *   trusted_device_driver: string,
     *   session_store_path: ?string,
     *   trusted_device_store_path: ?string,
     *   store_topology: string,
     *   store_fingerprint: string
     * }
     */
    private function operationalContext(Application $app): array
    {
        $sessionDriver = $this->normalizedString($app->config('auth.session.driver', 'memory'), 'memory');
        $trustedDeviceDriver = $this->normalizedString(
            $app->config('auth.trusted_devices.driver', $sessionDriver),
            $sessionDriver,
        );
        $sessionStorePath = $sessionDriver === 'file'
            ? $app->storagePath('framework/auth/sessions')
            : null;
        $trustedDeviceStorePath = $trustedDeviceDriver === 'file'
            ? $app->storagePath('framework/auth/trusted-devices')
            : null;
        $topology = match (true) {
            $sessionDriver === 'file' && $trustedDeviceDriver === 'file' => 'shared_file_store_candidate',
            $sessionDriver === 'file' || $trustedDeviceDriver === 'file' => 'mixed_driver_topology',
            default => 'in_memory_local_topology',
        };

        return [
            'app_name' => $this->normalizedString($app->config('app.name', 'VoltStack'), 'VoltStack'),
            'app_env' => $this->normalizedString($app->config('app.env', 'local'), 'local'),
            'session_driver' => $sessionDriver,
            'trusted_device_driver' => $trustedDeviceDriver,
            'session_store_path' => $sessionStorePath,
            'trusted_device_store_path' => $trustedDeviceStorePath,
            'store_topology' => $topology,
            'store_fingerprint' => sha1((string) json_encode([
                'session_driver' => $sessionDriver,
                'trusted_device_driver' => $trustedDeviceDriver,
                'session_store_path' => $sessionStorePath,
                'trusted_device_store_path' => $trustedDeviceStorePath,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array{
     *   evaluated: bool,
     *   audit_log_source: ?string,
     *   activity_drift: array<string, mixed>,
     *   operational_response: array<string, mixed>
     * }
     */
    private function distributedGuard(?string $auditLogSource): array
    {
        $report = new AuthSecurityCenterReportCommand($this->basePath);

        return $report->distributedGuardFromAuditLog($auditLogSource);
    }

    /**
     * @param array<string, mixed> $distributedGuard
     * @return array{
     *   scope: string,
     *   mutation_kind: string,
     *   evaluated: bool,
     *   authorization_mode: ?string,
     *   actor_privilege_level: ?string,
     *   actor_target_relation: ?string,
     *   actor_target_reason_code: ?string,
     *   actor_target_scope_relation: ?string,
     *   actor_target_scope_reason_code: ?string,
     *   should_deny: bool,
     *   reason_code: ?string,
     *   policy_reason_code: ?string,
     *   scope_policy: string,
     *   policy_source: string,
     *   allowed_scopes: list<string>,
     *   denied_scopes: list<string>
     * }
     */
    private function distributedGuardScopeDecision(
        string $scope,
        array $distributedGuard,
        ?string $authorizationMode,
        ?string $actorPrivilegeLevel,
        ?string $actorTargetRelation,
        ?string $actorTargetReasonCode,
        ?string $actorTargetScopeRelation,
        ?string $actorTargetScopeReasonCode,
    ): array
    {
        $operationalResponse = is_array($distributedGuard['operational_response'] ?? null)
            ? $distributedGuard['operational_response']
            : [];
        $policySource = 'global_scope_policy';
        $selectedPolicy = $operationalResponse;
        $selectedPolicy = $this->distributedGuardScopedPolicy(
            $selectedPolicy,
            'authorization_mode_scope_policies',
            $authorizationMode,
            'none',
            'authorization_mode_scope_policy',
            $policySource,
        );
        $selectedPolicy = $this->distributedGuardScopedPolicy(
            $selectedPolicy,
            'privilege_scope_policies',
            $actorPrivilegeLevel,
            null,
            'privilege_scope_policy',
            $policySource,
        );
        $selectedPolicy = $this->distributedGuardScopedPolicy(
            $selectedPolicy,
            'target_relation_scope_policies',
            $actorTargetRelation,
            null,
            'actor_target_relation_scope_policy',
            $policySource,
        );
        $selectedPolicy = $this->distributedGuardScopedPolicy(
            $selectedPolicy,
            'target_scope_relation_policies',
            $actorTargetScopeRelation,
            null,
            'actor_target_scope_relation_scope_policy',
            $policySource,
        );

        $allowedScopes = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($selectedPolicy['allowed_remote_mutation_scopes'] ?? []),
        ));
        $deniedScopes = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) ($selectedPolicy['denied_remote_mutation_scopes'] ?? []),
        ));
        /** @var array<string, string> $scopeReasonCodes */
        $scopeReasonCodes = array_filter(
            (array) ($selectedPolicy['scope_denial_reason_codes'] ?? []),
            static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $shouldDeny = (bool) ($distributedGuard['evaluated'] ?? false) && in_array($scope, $deniedScopes, true);
        $reasonCode = $shouldDeny
            ? ($scopeReasonCodes[$scope] ?? $selectedPolicy['remote_mutation_denial_reason_code'] ?? 'distributed_remote_mutation_guard')
            : null;
        $mutationKind = match ($scope) {
            'sessions' => 'session_revocation',
            'trusted-devices' => 'trusted_device_revocation',
            default => 'aggregated_device_revocation',
        };
        $policyReasonCode = $selectedPolicy['policy_reason_code'] ?? null;

        return [
            'scope' => $scope,
            'mutation_kind' => $mutationKind,
            'evaluated' => (bool) ($distributedGuard['evaluated'] ?? false),
            'authorization_mode' => $authorizationMode,
            'actor_privilege_level' => $actorPrivilegeLevel,
            'actor_target_relation' => $actorTargetRelation,
            'actor_target_reason_code' => $actorTargetReasonCode,
            'actor_target_scope_relation' => $actorTargetScopeRelation,
            'actor_target_scope_reason_code' => $actorTargetScopeReasonCode,
            'should_deny' => $shouldDeny,
            'reason_code' => is_string($reasonCode) ? $reasonCode : null,
            'policy_reason_code' => is_string($policyReasonCode) ? $policyReasonCode : null,
            'scope_policy' => is_string($selectedPolicy['remote_mutation_scope_policy'] ?? null)
                ? $selectedPolicy['remote_mutation_scope_policy']
                : 'allow_all',
            'policy_source' => $policySource,
            'allowed_scopes' => $allowedScopes,
            'denied_scopes' => $deniedScopes,
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function distributedGuardScopedPolicy(
        array $policy,
        string $policyKey,
        ?string $selector,
        ?string $fallbackSelector,
        string $source,
        string &$policySource,
    ): array {
        /** @var array<string, array<string, mixed>> $policies */
        $policies = array_filter(
            (array) ($policy[$policyKey] ?? []),
            static fn (mixed $value, mixed $key): bool => is_string($key) && is_array($value),
            ARRAY_FILTER_USE_BOTH,
        );

        if (is_string($selector) && isset($policies[$selector])) {
            $policySource = $source;

            return $policies[$selector];
        }

        if (is_string($fallbackSelector) && isset($policies[$fallbackSelector])) {
            $policySource = $source;

            return $policies[$fallbackSelector];
        }

        return $policy;
    }

    private function normalizedString(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    /**
     * @param list<string> $actorScopes
     * @return array{
     *   authorization_outcome: string,
     *   requested_scope: string,
     *   actor_scope_profile: string,
     *   actor_scope_count: int,
     *   actor_authorization_mode: ?string,
     *   actor_privilege_level: ?string,
     *   matched_total_resources: int,
     *   affected_total_resources: int,
     *   affected_resource_kinds: list<string>,
     *   dry_run: bool
     * }
     */
    private function administrativeMetrics(
        string $scope,
        bool $dryRun,
        string $authorizationOutcome,
        ?string $authorizationMode,
        ?string $actorPrivilegeLevel,
        array $actorScopes,
        int $matchedSessions,
        int $matchedTrustedDevices,
        int $affectedSessions,
        int $affectedTrustedDevices,
    ): array {
        $normalizedScopes = array_values(array_unique(array_map('strval', $actorScopes)));
        sort($normalizedScopes);

        $affectedResourceKinds = [];

        if ($affectedSessions > 0) {
            $affectedResourceKinds[] = 'sessions';
        }

        if ($affectedTrustedDevices > 0) {
            $affectedResourceKinds[] = 'trusted-devices';
        }

        return [
            'authorization_outcome' => $authorizationOutcome,
            'requested_scope' => $scope,
            'actor_scope_profile' => $this->actorScopeProfile($normalizedScopes),
            'actor_scope_count' => count($normalizedScopes),
            'actor_authorization_mode' => $authorizationMode,
            'actor_privilege_level' => $actorPrivilegeLevel,
            'matched_total_resources' => $matchedSessions + $matchedTrustedDevices,
            'affected_total_resources' => $affectedSessions + $affectedTrustedDevices,
            'affected_resource_kinds' => $affectedResourceKinds,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param list<string> $scopes
     */
    private function actorScopeProfile(array $scopes): string
    {
        $hasDevice = in_array('admin_device_management', $scopes, true);
        $hasSessions = $hasDevice || in_array('admin_session_management', $scopes, true);
        $hasTrustedDevices = $hasDevice || in_array('admin_trusted_device_management', $scopes, true);

        return match (true) {
            $hasSessions && $hasTrustedDevices => 'full',
            $hasSessions => 'sessions_only',
            $hasTrustedDevices => 'trusted_devices_only',
            default => 'none',
        };
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
        string $scope,
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

            $authorized = match ($scope) {
                'all' => $context->canAdministrativelyManageDeviceSessions()
                    && $context->canAdministrativelyManageTrustedDevices(),
                'sessions' => $context->canAdministrativelyManageDeviceSessions(),
                'trusted-devices' => $context->canAdministrativelyManageTrustedDevices(),
                default => false,
            };
            $authorizationMode = match ($scope) {
                'sessions' => $context->managementSessionAuthorizationMode(),
                'trusted-devices' => $context->managementTrustedDeviceAuthorizationMode(),
                default => $context->managementAuthorizationMode(),
            };
            $authorizationReasonCode = match ($scope) {
                'sessions' => $context->managementSessionAuthorizationReasonCode(),
                'trusted-devices' => $context->managementTrustedDeviceAuthorizationReasonCode(),
                default => $context->managementAuthorizationReasonCode(),
            };

            return [
                'context' => $context,
                'authorized' => $authorized,
                'authorization_mode' => $authorizationMode,
                'authorization_reason_code' => $authorizationReasonCode,
            ];
        }

        return null;
    }
}
