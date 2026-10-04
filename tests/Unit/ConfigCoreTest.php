<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quantum\Config\BuildContext;
use Quantum\Config\ConfigDocument;
use Quantum\Config\ConfigPath;
use Quantum\Config\ConfigRepository;
use Quantum\Config\ConfigSnapshot;
use Quantum\Config\MissingValue;
use Quantum\Config\SourceDescriptor;

final class ConfigCoreTest extends TestCase
{
    public function test_config_path_parses_parent_and_append(): void
    {
        $path = ConfigPath::fromString('cache.stores.file');

        self::assertSame('cache.stores.file', $path->value());
        self::assertSame(['cache', 'stores', 'file'], $path->segments());
        self::assertSame('cache.stores', (string) $path->parent());
        self::assertSame('cache.stores.file.path', (string) $path->append('path'));
    }

    public function test_config_path_rejects_empty_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConfigPath::fromString('cache..file');
    }

    public function test_repository_has_keeps_null_values_distinct_from_missing(): void
    {
        $repository = new ConfigRepository([
            'app' => [
                'subtitle' => null,
            ],
        ]);

        self::assertTrue($repository->has('app.subtitle'));
        self::assertNull($repository->get('app.subtitle'));
        self::assertFalse($repository->has('app.unknown'));
        self::assertSame(MissingValue::Token, $repository->get('app.unknown', MissingValue::Token));
    }

    public function test_repository_supports_path_based_access_without_breaking_dot_notation_api(): void
    {
        $repository = new ConfigRepository([
            'cache' => [
                'driver' => 'filesystem',
            ],
        ]);

        $repository->setPath(ConfigPath::fromString('cache.ttl'), 300);

        self::assertTrue($repository->hasPath(ConfigPath::fromString('cache.ttl')));
        self::assertSame(300, $repository->getPath(ConfigPath::fromString('cache.ttl')));
        self::assertSame('filesystem', $repository->get('cache.driver'));
    }

    public function test_repository_can_create_document_and_snapshot_views(): void
    {
        $repository = new ConfigRepository([
            'cache' => [
                'driver' => 'redis',
                'ttl' => 120,
            ],
        ]);

        $descriptor = new SourceDescriptor(
            id: 'local-cache',
            namespace: 'cache',
            layer: 'application',
            ordinal: 20,
            location: 'config/cache.php',
            revision: 'rev-1',
        );

        $document = $repository->document($descriptor, '1.0.0');
        $snapshot = $repository->snapshot(
            provenance: ['cache.driver' => 'config/cache.php'],
            schemaHash: 'schema-hash',
            configId: 'config-id',
        );

        self::assertInstanceOf(ConfigDocument::class, $document);
        self::assertSame(['driver' => 'redis', 'ttl' => 120], $document->payload());
        self::assertInstanceOf(ConfigSnapshot::class, $snapshot);
        self::assertSame('redis', $snapshot->get('cache.driver'));
        self::assertSame(120, $snapshot->get(ConfigPath::fromString('cache.ttl')));
        self::assertSame('schema-hash', $snapshot->schemaHash());
        self::assertSame('config-id', $snapshot->configId());
    }

    public function test_build_context_keeps_release_and_source_metadata(): void
    {
        $source = new SourceDescriptor(
            id: 'app-config',
            namespace: 'app',
            layer: 'application',
            ordinal: 10,
        );

        $context = new BuildContext(
            environment: 'testing',
            releaseId: 'release-001',
            schemaVersion: '1.0.0',
            deploymentConfigRevision: 'cfg-1',
            sources: [$source],
            limits: ['max_documents' => 64],
        );

        self::assertSame('testing', $context->environment());
        self::assertSame('release-001', $context->releaseId());
        self::assertSame('1.0.0', $context->schemaVersion());
        self::assertSame('cfg-1', $context->deploymentConfigRevision());
        self::assertCount(1, $context->sources());
        self::assertSame(['max_documents' => 64], $context->limits());
    }
}
