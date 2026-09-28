<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Manifest\FilesystemAuthorizationManifestStore;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;
use Quantum\Authorization\Metadata\AuthorizationRequirement;

final class FilesystemAuthorizationManifestStoreTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'authz_manifest_' . bin2hex(random_bytes(6));
        @mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_it_writes_reads_and_clears_payloads_on_filesystem(): void
    {
        $store = new FilesystemAuthorizationManifestStore($this->storage);
        $payload = new AuthorizationMetadataPayload(
            public: true,
            requirements: [
                new AuthorizationRequirement('files.view', 'file', 'route'),
                new AuthorizationRequirement('files.download', 'file', 'method'),
            ],
        );

        self::assertFalse($store->has($payload->fingerprint()));

        $store->put($payload);

        self::assertTrue($store->has($payload->fingerprint()));
        $retrieved = $store->get($payload->fingerprint());
        self::assertInstanceOf(AuthorizationMetadataPayload::class, $retrieved);
        self::assertTrue($retrieved->public());
        self::assertCount(2, $retrieved->requirements());
        self::assertSame('files.view', $retrieved->requirements()[0]->ability());
        self::assertSame('files.download', $retrieved->requirements()[1]->ability());
        self::assertSame($payload->fingerprint(), $retrieved->fingerprint());

        $cleared = $store->clear();

        self::assertSame(1, $cleared);
        self::assertFalse($store->has($payload->fingerprint()));
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
