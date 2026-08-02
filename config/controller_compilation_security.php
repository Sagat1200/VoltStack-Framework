<?php

declare(strict_types=1);

return [
    'enabled' => true,

    'environment' => [
        'runtime_generation' => false,
        'require_prebuilt_artifacts' => true,
        'fail_closed' => true,
    ],

    'sources' => [
        'allowed_roots' => [
            base_path('app'),
            base_path('src'),
        ],
        'allow_symlinks' => false,
        'snapshot_before_compile' => true,
    ],

    'compilers' => [
        'freeze_registry' => true,
        'allow_third_party' => false,
        'require_allowlist' => true,
        'reject_revoked' => true,
    ],

    'artifacts' => [
        'content_addressed' => true,
        'hash_algorithm' => 'sha256',
        'require_manifest_membership' => true,
        'validate_before_include' => true,
        'reject_orphans' => true,
        'max_size' => 4 * 1024 * 1024,
    ],

    'signatures' => [
        'required' => true,
        'algorithm' => 'ed25519',
        'allow_unsigned_local' => false,
        'reject_revoked_keys' => true,
    ],

    'manifests' => [
        'require_signature' => true,
        'validate_schema' => true,
        'reject_unknown_schema' => true,
    ],

    'builds' => [
        'immutable' => true,
        'atomic_activation' => true,
        'pin_per_execution' => true,
        'minimum_security_epoch' => 1,
        'reject_revoked' => true,
    ],

    'rollback' => [
        'require_authorization' => true,
        'require_reason' => true,
        'reject_downgrade' => true,
        'reject_revoked_builds' => true,
    ],

    'store' => [
        'runtime_read_only' => true,
        'separate_staging' => true,
        'seal_before_activation' => true,
        'reject_symlinks' => true,
    ],

    'opcache' => [
        'unique_paths_per_build' => true,
        'replace_in_place' => false,
        'restart_on_preload_change' => true,
    ],

    'remote_cache' => [
        'enabled' => false,
        'verify_every_download' => true,
        'runtime_write_access' => false,
    ],

    'supply_chain' => [
        'require_lockfile' => true,
        'allow_composer_plugins' => false,
        'block_known_vulnerabilities' => true,
        'pin_build_images_by_digest' => true,
    ],
];