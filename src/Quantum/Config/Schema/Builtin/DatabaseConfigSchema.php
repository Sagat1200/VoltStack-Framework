<?php

declare(strict_types=1);

namespace Quantum\Config\Schema\Builtin;

use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;

final class DatabaseConfigSchema
{
    public static function build(): ConfigSchema
    {
        $connectionNode = ConfigNode::map([
            'driver' => ConfigNode::enum([
                'sqlite',
                'sqlite3',
                'pdo.sqlite',
                'pgsql',
                'postgres',
                'postgresql',
                'pdo.pgsql',
                'mysql',
                'mariadb',
                'pdo.mysql',
            ], default: 'sqlite'),
            'database' => ConfigNode::string(required: true),
            'host' => ConfigNode::string(nullable: true),
            'port' => ConfigNode::integer(nullable: true),
            'username' => ConfigNode::string(nullable: true),
            'password' => ConfigNode::string(nullable: true),
            'charset' => ConfigNode::string(),
            'prefix' => ConfigNode::string(default: ''),
            'platform' => ConfigNode::string(),
            'dialect' => ConfigNode::string(),
            'options' => ConfigNode::map([], default: [], allowUnknown: true),
        ], allowUnknown: true);

        return new ConfigSchema('database', ConfigNode::map([
            'default' => ConfigNode::string(default: 'default'),
            'connections' => ConfigNode::dictionary($connectionNode, default: []),
            'runtime' => ConfigNode::map([
                'strict_scope' => ConfigNode::boolean(default: true),
            ], default: [], allowUnknown: true),
            'telemetry' => ConfigNode::map([
                'enabled' => ConfigNode::boolean(default: true),
            ], default: [], allowUnknown: true),
        ]));
    }
}
