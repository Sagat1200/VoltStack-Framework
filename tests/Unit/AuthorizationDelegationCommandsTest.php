<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationDelegationGrantCommand;
use Quantum\Authorization\Console\Commands\AuthorizationDelegationListCommand;
use Quantum\Authorization\Console\Commands\AuthorizationDelegationRevokeCommand;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationDelegationCommandsTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_delegation_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_delegation_cmd_app'] = null;
        unset($GLOBALS['__volt_authz_delegation_cmd_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_list_command_metadata(): void
    {
        $command = new AuthorizationDelegationListCommand($this->tempBasePath);

        self::assertSame('authz:delegation:list', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:delegation:list', $command->aliases());
        self::assertContains('authz:delegate:list', $command->aliases());
        self::assertContains('authz:list-delegations', $command->aliases());
    }

    public function test_grant_and_revoke_command_metadata(): void
    {
        $grant = new AuthorizationDelegationGrantCommand($this->tempBasePath);
        $revoke = new AuthorizationDelegationRevokeCommand($this->tempBasePath);

        self::assertSame('authz:delegation:grant', $grant->name());
        self::assertSame('Authorization', $grant->category());
        self::assertSame('authz:delegation:revoke', $revoke->name());
    }

    public function test_list_command_applies_all_filters_and_emits_json(): void
    {
        $admin = self::createAdminSpy();
        $admin->listResults = [
            ['trustee_id' => 't-1', 'grantor_id' => 'g-1', 'scope' => 'tenant:acme', 'type' => 'permission', 'value' => 'posts.publish', 'granted_at' => '2025-01-01T00:00:00+00:00'],
        ];
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationDelegationListCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:delegation:list',
            '--trustee-id=t-1',
            '--grantor-id=g-1',
            '--scope=tenant:acme',
            '--type=permission',
            '--value=posts.publish',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'trustee_id' => 't-1',
            'grantor_id' => 'g-1',
            'scope' => 'tenant:acme',
            'type' => 'permission',
            'value' => 'posts.publish',
        ], $admin->listFilters[0] ?? []);
        self::assertStringContainsString('posts.publish', $output->stdout());
        $decoded = json_decode($output->stdout(), true);
        self::assertIsArray($decoded);
        self::assertSame('t-1', $decoded[0]['trustee_id'] ?? null);
    }

    public function test_grant_command_requires_either_role_or_permission_not_both(): void
    {
        $admin = self::createAdminSpy();
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationDelegationGrantCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:delegation:grant',
            '--trustee-id=t-1',
            '--grantor-id=g-1',
            '--role=editor',
            '--permission=posts.edit',
        ]), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Se requiere exactamente una opcion', $output->stderr());
    }

    public function test_grant_command_succeeds_with_role_and_prints_verbose(): void
    {
        $admin = self::createAdminSpy();
        $admin->grantReturn = true;
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationDelegationGrantCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:delegation:grant',
            '--trustee-id=t-2',
            '--grantor-id=g-2',
            '--role=editor',
            '--scope=tenant:acme',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'trustee_id' => 't-2',
            'grantor_id' => 'g-2',
            'scope' => 'tenant:acme',
            'type' => 'role',
            'value' => 'editor',
        ], $admin->grants[0] ?? null);
        self::assertStringContainsString('Grant de delegation otorgado', $output->stdout());
    }

    public function test_revoke_command_reports_absence_when_no_matching_grant(): void
    {
        $admin = self::createAdminSpy();
        $admin->revokeReturn = false;
        $this->writeBootstrap(self::buildApp($admin));

        $command = new AuthorizationDelegationRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:delegation:revoke',
            '--trustee-id=t-missing',
            '--grantor-id=g-missing',
            '--permission=posts.delete',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No se encontro', $output->stdout());
    }

    public function test_grant_command_require_published_config_flag_rethrows_published_exception(): void
    {
        $this->expectException(\Quantum\Config\Publication\PublishedConfigurationRequiredException::class);

        // Use a spy app that throws on bootstrapApplication via app bootstrap
        // Simpler: construct command with empty base and the CLI should rethrow the PublishedConfig exception
        $spyApp = new class ($this->tempBasePath) extends Application {
            public function __construct(private readonly string $base)
            {
                parent::__construct($this->base);
            }
        };

        // Wrap the bootstrap expectation: make bootstrap throw PublishedConfigurationRequiredException
        // Use the writeBootstrap pattern with a thrower app
        $thrower = new class extends Application {
            public function __construct()
            {
                parent::__construct(sys_get_temp_dir());
            }

            public function boot(): void
            {
                throw new \Quantum\Config\Publication\PublishedConfigurationRequiredException(
                    'no active configuration generation exists for authorization gate.',
                );
            }
        };
        $this->writeBootstrap($thrower);

        $command = new AuthorizationDelegationGrantCommand($this->tempBasePath);
        $output = new Output();

        $command->handle(Input::fromArgv([
            'volt',
            'authz:delegation:grant',
            '--trustee-id=a',
            '--grantor-id=b',
            '--role=editor',
            '--require-published-config',
        ]), $output);
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_delegation_cmd_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_delegation_cmd_app' . "'" . '];' . PHP_EOL,
        );
    }

    private static function buildApp(DelegationAdministrationInterface $admin): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance(DelegationAdministrationInterface::class, $admin);

        return $app;
    }

    private static function createAdminSpy(): object
    {
        return new class implements DelegationAdministrationInterface {
            /** @var list<array> */
            public array $listFilters = [];

            /** @var list<array> */
            public array $listResults = [];

            /** @var list<array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string}> */
            public array $grants = [];

            /** @var list<array{trustee_id:string,grantor_id:string,scope:string,type:string,value:string}> */
            public array $revocations = [];

            public bool $grantReturn = false;
            public bool $revokeReturn = false;

            public function listDelegations(array $filters = []): array
            {
                $this->listFilters[] = $filters;

                return $this->listResults;
            }

            public function grantDelegation(
                string $trusteeId,
                string $grantorId,
                Role|Permission $grant,
                Scope|string $scope = Scope::GLOBAL,
            ): bool {
                if ($grant instanceof Role) {
                    $value = $grant->name;
                    $type = 'role';
                } else {
                    $value = $grant->name;
                    $type = 'permission';
                }
                $scopeValue = (string) ($scope instanceof Scope ? $scope : new Scope($scope));

                $this->grants[] = [
                    'trustee_id' => $trusteeId,
                    'grantor_id' => $grantorId,
                    'scope' => $scopeValue,
                    'type' => $type,
                    'value' => $value,
                ];

                return $this->grantReturn;
            }

            public function revokeDelegation(
                string $trusteeId,
                string $grantorId,
                Role|Permission $grant,
                Scope|string $scope = Scope::GLOBAL,
            ): bool {
                if ($grant instanceof Role) {
                    $value = $grant->name;
                    $type = 'role';
                } else {
                    $value = $grant->name;
                    $type = 'permission';
                }
                $scopeValue = (string) ($scope instanceof Scope ? $scope : new Scope($scope));

                $this->revocations[] = [
                    'trustee_id' => $trusteeId,
                    'grantor_id' => $grantorId,
                    'scope' => $scopeValue,
                    'type' => $type,
                    'value' => $value,
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
