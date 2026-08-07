<?php

declare(strict_types=1);

return [
    'enabled' => true,

    'defaults' => [
        'deny_by_default' => true,
        'fail_closed' => true,
        'authentication_required' => false,
        'tenant_required' => false,
    ],

    'controllers' => [
        'explicit_exposure' => false,
        'allowlist' => [],
        'allow_static_methods' => false,
        'allow_dynamic_targets' => false,
        'allow_non_public_methods' => false,
    ],

    'metadata' => [
        'freeze' => true,
        'most_restrictive_wins' => true,
        'reject_unsafe_overrides' => true,
    ],

    'authorization' => [
        'cache_per_execution' => true,
        'max_policy_evaluations' => 64,
        'abstain_as_deny' => true,
    ],

    'tenant' => [
        'strict_isolation' => true,
        'trust_client_tenant_id' => false,
        'hide_cross_tenant_resources' => true,
    ],

    'artifacts' => [
        'validate_manifest_membership' => true,
        'validate_fingerprints' => true,
        'require_read_only_builds' => true,
        'allow_runtime_generation' => false,
    ],

    'workers' => [
        'reset_security_context' => true,
        'detect_context_leaks' => true,
        'terminate_on_trust_failure' => true,
    ],

    'observability' => [
        'sanitize' => true,
        'audit_denials' => true,
        'record_sensitive_values' => false,
    ],
];
