<?php

declare(strict_types=1);

return [
    'default' => 'file',
    'default_pool' => 'default',

    'prefix' => 'voltstack',

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'prefix' => 'voltstack',
        ],
        'memory' => [
            'driver' => 'memory',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],

    'pools' => [
        'default' => [
            'store' => 'file',
            'prefix' => 'default',
            'default_ttl' => null,
        ],
        'runtime' => [
            'store' => 'memory',
            'prefix' => 'runtime',
            'default_ttl' => 300,
        ],
        'disabled' => [
            'store' => 'null',
            'prefix' => 'disabled',
            'default_ttl' => 60,
        ],
    ],

    'compiled' => [
        'views' => storage_path('framework/cache/compiled/views'),
        'pages' => storage_path('framework/cache/compiled/pages'),
    ],
];
