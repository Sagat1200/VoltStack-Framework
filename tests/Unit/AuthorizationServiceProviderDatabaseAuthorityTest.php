<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\DatabaseAuthorityRepository;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationServiceProviderDatabaseAuthorityTest extends TestCase
{
    private string $basePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-authz-provider-dbal-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'authority.sqlite';
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_defaults_include_memory_driver_and_database_table_configuration(): void
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);

        self::assertSame('memory', $config->get('authorization.authority.driver'));
        self::assertSame(
            DatabaseAuthorityRepository::DEFAULT_ROLE_GRANTS_TABLE,
            $config->get('authorization.authority.database.tables.role_grants'),
        );
        self::assertSame(
            DatabaseAuthorityRepository::DEFAULT_PERMISSION_GRANTS_TABLE,
            $config->get('authorization.authority.database.tables.permission_grants'),
        );
        self::assertSame(
            DatabaseAuthorityRepository::DEFAULT_ROLE_PERMISSIONS_TABLE,
            $config->get('authorization.authority.database.tables.role_permissions'),
        );
    }

    public function test_database_driver_resolves_database_authority_repository_when_memoize_is_disabled(): void
    {
        $app = $this->makeApplication();
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.driver', 'database');
        $config->set('authorization.authority.memoize', false);

        $repository = $app->make(AuthorityRepositoryInterface::class);

        self::assertInstanceOf(DatabaseAuthorityRepository::class, $repository);
    }

    public function test_database_driver_is_wrapped_by_cached_repository_when_memoize_is_enabled(): void
    {
        $app = $this->makeApplication();
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.driver', 'database');
        $config->set('authorization.authority.memoize', true);

        $repository = $app->make(AuthorityRepositoryInterface::class);

        self::assertInstanceOf(CachedAuthorityRepository::class, $repository);

        $reflection = new \ReflectionObject($repository);
        $inner = $reflection->getProperty('inner');
        $inner->setAccessible(true);

        self::assertInstanceOf(DatabaseAuthorityRepository::class, $inner->getValue($repository));
    }

    private function makeApplication(): Application
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $app;
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
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
