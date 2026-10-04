<?php

declare(strict_types=1);

namespace Quantum\Config\Schema\Builtin;

use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;

final class CacheConfigSchema
{
    public static function build(): ConfigSchema
    {
        return new ConfigSchema('cache', ConfigNode::map([
            'default' => ConfigNode::enum(['file', 'memory', 'null'], default: 'file'),
            'prefix' => ConfigNode::string(default: 'voltstack'),
            'stores' => ConfigNode::dictionary(
                ConfigNode::map([
                    'driver' => ConfigNode::enum(['file', 'memory', 'null'], required: true),
                    'path' => ConfigNode::string(),
                    'prefix' => ConfigNode::string(),
                ]),
                default: [],
            ),
            'compiled' => ConfigNode::map([
                'views' => ConfigNode::string(),
                'pages' => ConfigNode::string(),
            ], default: []),
        ]));
    }
}
