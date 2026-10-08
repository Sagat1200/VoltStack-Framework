<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\FileVersionAuthority;

final class FileVersionAuthorityTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-file-version-authority-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_current_version_defaults_to_v1_when_scope_is_missing(): void
    {
        $authority = new FileVersionAuthority($this->storagePath);

        self::assertSame('v1', $authority->currentVersion('authorization.consistency.authority.global'));
    }

    public function test_bump_persists_version_across_separate_instances(): void
    {
        $first = new FileVersionAuthority($this->storagePath);
        $second = new FileVersionAuthority($this->storagePath);
        $scope = 'authorization.consistency.authority.principal_scope.abc123';

        self::assertSame('v1', $first->currentVersion($scope));
        self::assertSame('v2', $first->bump($scope));
        self::assertSame('v2', $second->currentVersion($scope));
        self::assertSame('v3', $second->bump($scope));
        self::assertSame('v3', $first->currentVersion($scope));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
