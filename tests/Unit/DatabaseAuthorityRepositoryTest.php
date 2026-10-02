<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\DatabaseAuthorityRepository;
use Quantum\Authorization\Authority\Scope;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseInterface;
use VoltStack\Framework\Application;

final class DatabaseAuthorityRepositoryTest extends TestCase
{
    private string $basePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-authz-dbal-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'authority.sqlite';
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_database_repository_reads_roles_direct_permissions_and_effective_permissions(): void
    {
        $app = $this->makeApplication();
        $database = $app->make(DatabaseInterface::class);
        $this->seedAuthorityTables($database);

        $repository = new DatabaseAuthorityRepository($database);

        $roles = $repository->rolesForPrincipal('u_1', Scope::GLOBAL);
        $direct = $repository->directPermissionsForPrincipal('u_1', 'tenant:acme');
        $effective = $repository->effectivePermissionsForPrincipal('u_1', 'tenant:acme');

        self::assertCount(1, $roles);
        self::assertSame('editor', $roles[0]->name);
        self::assertSame(['posts.edit', 'posts.view'], $roles[0]->permissionNames());

        self::assertCount(1, $direct);
        self::assertSame('posts.publish', $direct[0]->name);

        $effectiveNames = array_values(array_map(static fn ($permission): string => $permission->name, $effective));
        sort($effectiveNames);

        self::assertSame(
            ['admin.*', 'posts.edit', 'posts.publish', 'posts.view'],
            $effectiveNames,
        );
        self::assertTrue($repository->hasPermission('u_1', 'admin.users.create', 'tenant:acme'));
        self::assertTrue($repository->hasPermission('u_1', 'posts.publish', 'tenant:acme'));

        $database->connection()->disconnect();
    }

    public function test_scopes_for_principal_are_collected_from_role_and_permission_tables(): void
    {
        $app = $this->makeApplication();
        $database = $app->make(DatabaseInterface::class);
        $this->seedAuthorityTables($database);

        $repository = new DatabaseAuthorityRepository($database);
        $scopes = $repository->scopesForPrincipal('u_1');

        self::assertSame(
            ['global', 'tenant:acme'],
            array_values(array_map(static fn (Scope $scope): string => (string) $scope, $scopes)),
        );

        $database->connection()->disconnect();
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

    private function seedAuthorityTables(DatabaseInterface $database): void
    {
        $pdo = $database->connection()->pdo();
        $pdo->exec('CREATE TABLE authorization_role_grants (principal_id TEXT NOT NULL, scope TEXT NOT NULL, role_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE authorization_permission_grants (principal_id TEXT NOT NULL, scope TEXT NOT NULL, permission_name TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE authorization_role_permissions (role_name TEXT NOT NULL, permission_name TEXT NOT NULL)');

        $pdo->exec("INSERT INTO authorization_role_permissions (role_name, permission_name) VALUES ('editor', 'posts.edit')");
        $pdo->exec("INSERT INTO authorization_role_permissions (role_name, permission_name) VALUES ('editor', 'posts.view')");
        $pdo->exec("INSERT INTO authorization_role_permissions (role_name, permission_name) VALUES ('admin', 'admin.*')");

        $pdo->exec("INSERT INTO authorization_role_grants (principal_id, scope, role_name) VALUES ('u_1', 'global', 'editor')");
        $pdo->exec("INSERT INTO authorization_role_grants (principal_id, scope, role_name) VALUES ('u_1', 'tenant:acme', 'admin')");
        $pdo->exec("INSERT INTO authorization_permission_grants (principal_id, scope, permission_name) VALUES ('u_1', 'tenant:acme', 'posts.publish')");
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
