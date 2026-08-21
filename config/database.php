<?php

declare(strict_types=1);

$appEnv = $_ENV['APP_ENV'] ?? 'local';
$sqlitePath = $_ENV['DB_DATABASE'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'app.sqlite';

return [
    'default' => $_ENV['DB_CONNECTION'] ?? 'primary',

    'connections' => [
        'primary' => [
            'driver' => $_ENV['DB_DRIVER'] ?? 'sqlite',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
            'database' => $_ENV['DB_NAME'] ?? '',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'path' => $sqlitePath,
            'memory' => filter_var($_ENV['DB_MEMORY'] ?? 'false', FILTER_VALIDATE_BOOL),
            'journal_mode' => $_ENV['DB_SQLITE_JOURNAL_MODE'] ?? 'WAL',
            'synchronous' => $_ENV['DB_SQLITE_SYNCHRONOUS'] ?? 'NORMAL',
            'busy_timeout_ms' => (int) ($_ENV['DB_SQLITE_BUSY_TIMEOUT_MS'] ?? 5000),
            'sslmode' => $_ENV['DB_SSLMODE'] ?? null,
            'application_name' => $_ENV['DB_APP_NAME'] ?? 'voltstack',
        ],
    ],

    'metadata' => [
        'entity_paths' => [
            'app/Entities',
            'app/Domain/Entities',
        ],
        'entities' => [],
        'cache_dir' => 'storage/cache/orm/metadata',
        'custom_types' => [],
    ],

    'timeouts' => [
        'soft_timeout_ms' => (int) ($_ENV['DB_SOFT_TIMEOUT_MS'] ?? 30000),
    ],

    'query_limits' => [
        'max_rows' => (int) ($_ENV['DB_MAX_ROWS'] ?? 100000),
        'max_depth' => (int) ($_ENV['DB_MAX_DEPTH'] ?? 32),
    ],

    'security' => [
        'redact_sensitive' => true,
        'policies' => [
            'soft_delete_filter' => true,
        ],
    ],

    'cli' => [
        'allow_raw_query' => $appEnv !== 'production',
    ],
];
