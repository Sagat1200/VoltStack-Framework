<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationAuthorityGrantCommand;
use Quantum\Authorization\Console\Commands\AuthorizationAuthorityListCommand;
use Quantum\Authorization\Console\Commands\AuthorizationAuthorityRevokeCommand;
use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationAuthorityCommandsTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_authority_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_authority_test_app'] = null;
        unset($GLOBALS['__volt_authz_authority_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_list_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationAuthorityListCommand($this->tempBasePath);

        self::assertSame('authz:authority:list', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:authority:list', $command->aliases());
        self::assertContains('authz:list-authority', $command->aliases());
    }

    public function test_grant_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationAuthorityGrantCommand($this->tempBasePath);

        self::assertSame('authz:authority:grant', $command->name());
        self::assertContains('authz:grant-authority', $command->aliases());
    }

    public function test_revoke_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationAuthorityRevokeCommand($this->tempBasePath);

        self::assertSame('authz:authority:revoke', $command->name());
        self::assertContains('authz:revoke-authority', $command->aliases());
    }

    public function test_list_command_applies_filters_and_can_emit_json(): void
    {
        $admin = self::createAdminSpy();
        $admin->listResults = [
            ['principal_id' => '42', 'scope' => 'tenant:acme', 'type' => 'permission', 'value' => 'posts.publish'],
        ];
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationAuthorityListCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:authority:list',
            '--principal-id=42',
            '--scope=tenant:acme',
            '--type=permission',
            '--value=posts.publish',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'principal_id' => '42',
            'scope' => 'tenant:acme',
            'type' => 'permission',
            'value' => 'posts.publish',
        ], $admin->listFilters[0] ?? []);
        self::assertStringContainsString('"posts.publish"', $output->stdout());
    }

    public function test_grant_command_requires_principal_and_single_selection(): void
    {
        $admin = self::createAdminSpy();
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationAuthorityGrantCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:authority:grant',
            '--principal-id=42',
            '--role=admin',
            '--permission=posts.publish',
        ]), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('exactamente uno', $output->stderr());
    }

    public function test_grant_command_reports_success_and_verbose_output(): void
    {
        $admin = self::createAdminSpy();
        $admin->grantReturn = true;
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationAuthorityGrantCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:authority:grant',
            '--principal-id=42',
            '--scope=tenant:acme',
            '--permission=posts.publish',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'principal_id' => '42',
            'scope' => 'tenant:acme',
            'type' => 'permission',
            'value' => 'posts.publish',
        ], $admin->grants[0] ?? null);
        self::assertStringContainsString('Grant de authority aplicado correctamente', $output->stdout());
        self::assertStringContainsString('Repositorio administrativo:', $output->stdout());
    }

    public function test_revoke_command_dry_run_does_not_touch_admin_repository(): void
    {
        $admin = self::createAdminSpy();
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationAuthorityRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:authority:revoke',
            '--principal-id=42',
            '--role=admin',
            '--dry-run',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertCount(0, $admin->revocations);
        self::assertStringContainsString('[dry-run]', $output->stdout());
    }

    public function test_revoke_command_reports_when_no_grant_matches(): void
    {
        $admin = self::createAdminSpy();
        $admin->revokeReturn = false;
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationAuthorityRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:authority:revoke',
            '--principal-id=42',
            '--permission=posts.publish',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No se encontro un grant coincidente', $output->stdout());
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_authority_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_authority_test_app' . "'" . '];' . PHP_EOL,
        );
    }

    private static function buildApp(AuthorityAdministrationInterface $admin): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorityAdministrationInterface::class, $admin);

        return $app;
    }

    private static function createAdminSpy(): object
    {
        return new class implements AuthorityAdministrationInterface {
            /** @var list<array{principal_id?:string,scope?:string,type?:string,value?:string}> */
            public array $listFilters = [];

            /** @var list<array{principal_id:string,scope:string,type:string,value:string}> */
            public array $listResults = [];

            /** @var list<array{principal_id:string,scope:string,type:string,value:string}> */
            public array $grants = [];

            /** @var list<array{principal_id:string,scope:string,type:string,value:string}> */
            public array $revocations = [];

            public bool $grantReturn = false;

            public bool $revokeReturn = false;

            public function listGrants(array $filters = []): array
            {
                $this->listFilters[] = $filters;

                return $this->listResults;
            }

            public function grantRole(
                string $principalId,
                \Quantum\Authorization\Authority\Role|string $role,
                \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL,
            ): bool {
                $this->grants[] = [
                    'principal_id' => $principalId,
                    'scope' => (string) ($scope instanceof \Quantum\Authorization\Authority\Scope ? $scope : new \Quantum\Authorization\Authority\Scope($scope)),
                    'type' => 'role',
                    'value' => ($role instanceof \Quantum\Authorization\Authority\Role ? $role : new \Quantum\Authorization\Authority\Role($role))->name,
                ];

                return $this->grantReturn;
            }

            public function grantPermission(
                string $principalId,
                \Quantum\Authorization\Authority\Permission|string $permission,
                \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL,
            ): bool {
                $this->grants[] = [
                    'principal_id' => $principalId,
                    'scope' => (string) ($scope instanceof \Quantum\Authorization\Authority\Scope ? $scope : new \Quantum\Authorization\Authority\Scope($scope)),
                    'type' => 'permission',
                    'value' => ($permission instanceof \Quantum\Authorization\Authority\Permission ? $permission : \Quantum\Authorization\Authority\Permission::from($permission))->name,
                ];

                return $this->grantReturn;
            }

            public function revokeRole(
                string $principalId,
                \Quantum\Authorization\Authority\Role|string $role,
                \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL,
            ): bool {
                $this->revocations[] = [
                    'principal_id' => $principalId,
                    'scope' => (string) ($scope instanceof \Quantum\Authorization\Authority\Scope ? $scope : new \Quantum\Authorization\Authority\Scope($scope)),
                    'type' => 'role',
                    'value' => ($role instanceof \Quantum\Authorization\Authority\Role ? $role : new \Quantum\Authorization\Authority\Role($role))->name,
                ];

                return $this->revokeReturn;
            }

            public function revokePermission(
                string $principalId,
                \Quantum\Authorization\Authority\Permission|string $permission,
                \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL,
            ): bool {
                $this->revocations[] = [
                    'principal_id' => $principalId,
                    'scope' => (string) ($scope instanceof \Quantum\Authorization\Authority\Scope ? $scope : new \Quantum\Authorization\Authority\Scope($scope)),
                    'type' => 'permission',
                    'value' => ($permission instanceof \Quantum\Authorization\Authority\Permission ? $permission : \Quantum\Authorization\Authority\Permission::from($permission))->name,
                ];

                return $this->revokeReturn;
            }
        };
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
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
