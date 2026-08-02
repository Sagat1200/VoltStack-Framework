<?php

declare(strict_types=1);

return [
    'deterministic' => true,

    'clock' => 'fake',

    'ids' => 'deterministic',

    'artifacts' => [
        'store' => 'memory',
        'validate_serialization' => true,
    ],

    'equivalence' => [
        'enabled' => true,
        'compare_headers' => true,
        'compare_events' => true,
        'ignore_volatile_values' => true,
    ],

    'workers' => [
        'simulate_persistence' => true,
        'detect_leaks' => true,
        'requests_per_test' => 3,
    ],

    'observability' => [
        'record_events' => true,
        'record_metrics' => true,
        'record_traces' => true,
        'assert_sanitization' => true,
    ],

    'failure_injection' => [
        'enabled' => true,
    ],

    'snapshots' => [
        'normalize_paths' => true,
        'normalize_timestamps' => true,
        'normalize_ids' => true,
    ],
];