<?php

declare(strict_types=1);

namespace Quantum\Config\Schema\Builtin;

use Quantum\Config\Schema\ConfigNode;
use Quantum\Config\Schema\ConfigSchema;

final class ExceptionsConfigSchema
{
    public static function build(): ConfigSchema
    {
        return new ConfigSchema('exceptions', ConfigNode::map([
            'schema_version' => ConfigNode::integer(default: 1),
            'environment' => ConfigNode::enum(['development', 'test', 'staging', 'production'], default: 'production'),
            'debug' => ConfigNode::boolean(default: false),
            'default_locale' => ConfigNode::string(default: 'es'),
            'fallback_locale' => ConfigNode::string(default: 'en'),
            'runtime' => ConfigNode::string(default: 'frankenphp'),
            'limits' => ConfigNode::map([
                'handling_depth' => ConfigNode::integer(default: 2),
                'causes' => ConfigNode::integer(default: 8),
                'frames_per_cause' => ConfigNode::integer(default: 32),
                'message_bytes' => ConfigNode::integer(default: 2048),
                'snapshot_bytes' => ConfigNode::integer(default: 32768),
                'attributes' => ConfigNode::integer(default: 32),
                'attribute_depth' => ConfigNode::integer(default: 4),
                'occurrences_per_scope' => ConfigNode::integer(default: 128),
                'mapping_candidates' => ConfigNode::integer(default: 64),
                'field_errors' => ConfigNode::integer(default: 100),
                'output_bytes' => ConfigNode::integer(default: 65536),
                'header_bytes' => ConfigNode::integer(default: 8192),
            ], default: []),
            'reporting' => ConfigNode::map([
                'enabled' => ConfigNode::boolean(default: true),
                'sync_budget_ms' => ConfigNode::integer(default: 50),
                'sample_rate' => ConfigNode::number(default: 1.0),
                'buffer_records' => ConfigNode::integer(default: 256),
                'buffer_bytes' => ConfigNode::integer(default: 2097152),
                'buffer_ttl_seconds' => ConfigNode::integer(default: 60),
                'reporters' => ConfigNode::listOf(
                    ConfigNode::string(),
                    default: ['exceptions.log'],
                ),
                'ignore_codes' => ConfigNode::listOf(
                    ConfigNode::string(),
                    default: [
                        'validation.failed',
                        'resource.not_found',
                        'authentication.required',
                        'authorization.denied',
                        'authorization.challenge',
                    ],
                ),
            ], default: []),
            'rendering' => ConfigNode::map([
                'api_format' => ConfigNode::enum(['problem_json', 'json'], default: 'problem_json'),
                'browser_format' => ConfigNode::enum(['html', 'json'], default: 'html'),
                'spa_versions' => ConfigNode::listOf(
                    ConfigNode::integer(),
                    default: [1],
                ),
                'cache_control' => ConfigNode::enum(['no-store'], default: 'no-store'),
            ], default: []),
            'recovery' => ConfigNode::map([
                'automatic_replay' => ConfigNode::boolean(default: false),
            ], default: []),
            'privacy' => ConfigNode::map([
                'capture_arguments' => ConfigNode::boolean(default: false),
                'capture_request_body' => ConfigNode::boolean(default: false),
                'public_trace_id' => ConfigNode::boolean(default: false),
                'diagnostic_retention_days' => ConfigNode::integer(default: 7),
                'aggregate_retention_days' => ConfigNode::integer(default: 30),
            ], default: []),
            'compilation' => ConfigNode::map([
                'required_in_production' => ConfigNode::boolean(default: true),
            ], default: []),
            'rules' => ConfigNode::listOf(
                ConfigNode::map([
                    'id' => ConfigNode::string(required: true),
                    'exceptionType' => ConfigNode::string(required: true),
                    'priority' => ConfigNode::integer(default: 0),
                    'serviceId' => ConfigNode::string(required: true),
                    'exclusive' => ConfigNode::boolean(default: false),
                    'predicates' => ConfigNode::listOf(ConfigNode::string(), default: []),
                ]),
                default: [],
            ),
        ]));
    }
}
