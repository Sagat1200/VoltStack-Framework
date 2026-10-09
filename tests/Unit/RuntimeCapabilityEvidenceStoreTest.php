<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Compilation\BuildManifest;
use VoltStack\Runtime\Evidence\RuntimeCapabilityEvidenceStore;
use VoltStack\Runtime\RuntimeCapabilities;

final class RuntimeCapabilityEvidenceStoreTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-evidence-store-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    public function test_it_publishes_and_reads_the_current_runtime_capability_evidence(): void
    {
        $store = new RuntimeCapabilityEvidenceStore(
            manifest: new BuildManifest($this->storageRoot),
            storageRoot: $this->storageRoot,
        );

        $artifact = $store->publish(
            driver: 'frankenphp',
            platform: 'windows-frankenphp-dev',
            capabilities: new RuntimeCapabilities(
                persistent: true,
                concurrent: false,
                streaming: false,
                drainControl: true,
                nativeHttp: true,
                evidenceLevel: 'native-verified',
                nativeIntegrationVerified: true,
                evidenceNotes: ['Validado contra runtime real de FrankenPHP.'],
            ),
        );

        $store->activateGeneration($artifact->generationId());
        $current = $store->currentArtifact();

        self::assertNotNull($current);
        self::assertSame($artifact->generationId(), $current->generationId());
        self::assertSame('frankenphp', $current->driver());
        self::assertSame('windows-frankenphp-dev', $current->platform());
        self::assertSame('native-verified', $current->capabilities()->evidenceLevel());
        self::assertTrue($current->capabilities()->nativeIntegrationVerified());
        self::assertTrue($current->capabilities()->persistent());
        self::assertTrue($current->capabilities()->drainControl());
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
