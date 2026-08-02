<?php

declare(strict_types=1);

return [
    'resolution' => [
        'explicit_targets_only' => true,
        'allowed_namespaces' => [
            'App\\Http\\Controllers\\',
            'App\\Actions\\',
        ],
        'allow_static' => false,
        'allow_global_functions' => false,
        'max_alias_depth' => 4,
    ],

    'exposure' => [
        'explicit_actions_only' => true,
        'allow_inherited_actions' => false,
        'bind_actions_to_routes' => true,
        'reject_magic_methods' => true,
    ],

    'parameters' => [
        'strict_sources' => true,
        'reject_source_conflicts' => true,
        'max_parameters' => 64,
        'max_depth' => 16,
        'max_collection_items' => 1000,
        'max_total_bytes' => 2 * 1024 * 1024,
    ],

    'dto' => [
        'allowlist_only' => true,
        'unknown_fields' => 'reject',
        'allow_polymorphism' => false,
        'constructor_only' => true,
        'max_depth' => 8,
    ],

    'models' => [
        'allowlist_only' => true,
        'tenant_first' => true,
        'require_resource_authorization' => true,
        'hide_cross_tenant_resources' => true,
        'allow_soft_deleted' => false,
    ],

    'uploads' => [
        'max_files' => 10,
        'max_file_size' => 10 * 1024 * 1024,
        'max_total_size' => 25 * 1024 * 1024,
        'temporary_isolation' => true,
        'scan_before_persist' => true,
    ],

    'interceptors' => [
        'reserve_security_phases' => true,
        'mandatory' => [
            'authentication',
            'tenant',
            'authorization',
            'invocation_guard',
            'security_finalize',
        ],
    ],

    'invocation' => [
        'max_invocations_per_execution' => 1,
        'seal_arguments' => true,
        'require_authorization_token' => true,
    ],

    'subrequests' => [
        'max_depth' => 8,
        'inherit_identity' => true,
        'inherit_authorization' => false,
        'allow_tenant_switch' => false,
    ],

    'retries' => [
        'max_attempts' => 3,
        'require_idempotency_for_writes' => true,
        'revalidate_authorization' => true,
    ],

    'workers' => [
        'validate_after_request' => true,
        'clear_security_context' => true,
        'clear_decision_cache' => true,
        'terminate_on_irrecoverable_leak' => true,
    ],
];