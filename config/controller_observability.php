<?php

declare(strict_types=1);

return [
    'enabled' => true,

    'events' => [
        'enabled' => true,
        'buffered' => true,
    ],

    'metrics' => [
        'enabled' => true,
        'prefix' => 'voltstack.controllers',
        'cardinality_guard' => true,
    ],

    'tracing' => [
        'enabled' => true,
        'propagate' => true,
        'open_telemetry' => true,
    ],

    'logging' => [
        'enabled' => true,
        'structured' => true,
        'deduplicate_exceptions' => true,
    ],

    'timeline' => [
        'enabled' => false,
        'retain_on_error' => true,
        'retain_when_slow' => true,
    ],

    'profiling' => [
        'mode' => 'sampled',
        'sample_rate' => 0.05,
    ],

    'sampling' => [
        'strategy' => 'parent_based',
        'rate' => 0.10,
        'retain_errors' => true,
        'retain_slow' => true,
    ],

    'sanitization' => [
        'enabled' => true,
        'record_arguments' => false,
        'record_bodies' => false,
        'hide_paths' => true,
    ],

    'exporters' => [
        'default' => ['log', 'metrics', 'otel'],
        'flush_on_completion' => true,
    ],

    'workers' => [
        'reset_after_execution' => true,
        'detect_context_leaks' => true,
    ],

    'compiled' => [
        'enabled' => true,
        'strict' => false,
    ],
];