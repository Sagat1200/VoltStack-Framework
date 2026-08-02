<?php

declare(strict_types=1);

return [
    'enabled' => true,

    'mode' => env('APP_ENV') === 'production'
        ? 'production'
        : 'development',

    'strict' => env('APP_ENV') === 'production',

    'paths' => [
        'root' => storage_path('framework/controllers'),
        'builds' => storage_path('framework/controllers/builds'),
        'current' => storage_path('framework/controllers/current'),
    ],

    'artifacts' => [
        'format' => 'php',
        'atomic_write' => true,
        'validate_after_write' => true,
        'shared_artifacts' => true,
    ],

    'incremental' => [
        'enabled' => true,
        'reuse_unchanged' => true,
        'prune_stale' => true,
    ],

    'cache' => [
        'execution' => true,
        'request' => true,
        'worker' => true,
        'worker_max_artifacts' => 2048,
    ],

    'fallback' => [
        'dynamic' => env('APP_ENV') !== 'production',
        'report' => true,
    ],

    'warmup' => [
        'enabled' => true,
        'validate_all' => true,
        'hot_routes' => [],
    ],

    'preload' => [
        'enabled' => false,
        'max_files' => 500,
        'max_estimated_bytes' => 32 * 1024 * 1024,
    ],

    'deployment' => [
        'atomic_activation' => true,
        'retain_builds' => 3,
        'rollback_enabled' => true,
    ],

    'workers' => [
        'pin_build_per_execution' => true,
        'reload_strategy' => 'restart',
    ],

    'debug' => [
        'source_maps' => env('APP_DEBUG', false),
        'debug_symbols' => env('APP_DEBUG', false),
        'preserve_failed_workspace' => env('APP_DEBUG', false),
    ],
];