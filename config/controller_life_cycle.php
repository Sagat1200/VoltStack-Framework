<?php


declare(strict_types=1);


return [
    'mode' => 'auto',

    'compiled' => [
        'enabled' => true,
        'strict' => false,
        'path' => storage_path('framework/controllers/lifecycle'),
    ],

    'cancellation' => [
        'enabled' => true,
        'checkpoints' => true,
        'propagate_to_children' => true,
    ],

    'timeouts' => [
        'enabled' => true,
        'default' => null,
    ],

    'short_circuit' => [
        'enabled' => true,
        'record_origin' => true,
    ],

    'resources' => [
        'track' => true,
        'strict_ownership' => true,
        'release_reverse_order' => true,
    ],

    'cleanup' => [
        'always' => true,
        'fail_on_leak' => false,
    ],

    'workers' => [
        'reset_after_execution' => true,
        'evaluate_health' => true,
        'terminate_on_corruption' => true,
    ],

    'observability' => [
        'events' => true,
        'metrics' => true,
        'tracing' => true,
        'snapshots' => false,
        'timeline' => true,
    ],
];