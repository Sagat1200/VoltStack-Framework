<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationRelationshipsListCommand;
use Quantum\Authorization\Console\Commands\AuthorizationRelationshipsRevokeCommand;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationRelationshipCommandsTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_rel_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_relationship_test_app'] = null;
        unset($GLOBALS['__volt_authz_relationship_test_app']);

        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_list_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationRelationshipsListCommand($this->tempBasePath);

        self::assertSame('authz:relationships:list', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:relationships:list', $command->aliases());
        self::assertContains('authz:list-relationships', $command->aliases());
    }

    public function test_revoke_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationRelationshipsRevokeCommand($this->tempBasePath);

        self::assertSame('authz:relationships:revoke', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:relationships:revoke', $command->aliases());
        self::assertContains('authz:revoke-relationship', $command->aliases());
    }

    public function test_list_command_reports_empty_result(): void
    {
        $admin = self::createAdminSpy();
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsListCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv(['volt', 'authz:relationships:list']), $output);

        self::assertSame(0, $exit);
        self::assertSame([], $admin->listFilters[0] ?? []);
        self::assertStringContainsString('No se encontraron relaciones', $output->stdout());
    }

    public function test_list_command_applies_filters_and_can_emit_json(): void
    {
        $admin = self::createAdminSpy();
        $admin->listResults = [
            ['principal_id' => '42', 'relation' => 'owner', 'resource_key' => 'string:doc-1', 'scope' => 'global'],
        ];
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsListCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:relationships:list',
            '--principal-id=42',
            '--relation=owner',
            '--scope=global',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'principal_id' => '42',
            'relation' => 'owner',
            'scope' => 'global',
        ], $admin->listFilters[0] ?? []);
        self::assertStringContainsString('"principal_id": "42"', $output->stdout());
        self::assertStringContainsString('"resource_key": "string:doc-1"', $output->stdout());
    }

    public function test_revoke_command_requires_mandatory_options(): void
    {
        $admin = self::createAdminSpy();
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv(['volt', 'authz:relationships:revoke', '--principal-id=42']), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Faltan opciones requeridas', $output->stderr());
    }

    public function test_revoke_command_dry_run_does_not_touch_admin_repository(): void
    {
        $admin = self::createAdminSpy();
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:relationships:revoke',
            '--principal-id=42',
            '--relation=owner',
            '--resource-key=string:doc-1',
            '--scope=global',
            '--dry-run',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertCount(0, $admin->revocations);
        self::assertStringContainsString('[dry-run]', $output->stdout());
    }

    public function test_revoke_command_reports_success_when_relationship_is_removed(): void
    {
        $admin = self::createAdminSpy();
        $admin->revokeReturn = true;
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:relationships:revoke',
            '--principal-id=42',
            '--relation=owner',
            '--resource-key=string:doc-1',
            '--scope=tenant:acme',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([
            'principal_id' => '42',
            'relation' => 'owner',
            'resource_key' => 'string:doc-1',
            'scope' => 'tenant:acme',
        ], $admin->revocations[0] ?? null);
        self::assertStringContainsString('Relacion revocada correctamente', $output->stdout());
        self::assertStringContainsString('Repositorio administrativo:', $output->stdout());
    }

    public function test_revoke_command_reports_when_nothing_matches(): void
    {
        $admin = self::createAdminSpy();
        $admin->revokeReturn = false;
        $app = self::buildApp($admin);
        $this->writeBootstrap($app);

        $command = new AuthorizationRelationshipsRevokeCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:relationships:revoke',
            '--principal-id=24',
            '--relation=viewer',
            '--resource-key=string:doc-9',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No se encontro una relacion coincidente', $output->stdout());
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_relationship_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_relationship_test_app' . "'" . '];' . PHP_EOL,
        );
    }

    private static function buildApp(RelationshipAdministrationInterface $admin): Application
    {
        $app = new Application(sys_get_temp_dir());
        $app->instance(RelationshipAdministrationInterface::class, $admin);

        return $app;
    }

    private static function createAdminSpy(): object
    {
        return new class implements RelationshipAdministrationInterface {
            /** @var list<array{principal_id?:string,relation?:string,scope?:string}> */
            public array $listFilters = [];

            /** @var list<array{principal_id:string,relation:string,resource_key:string,scope:string}> */
            public array $listResults = [];

            /** @var list<array{principal_id:string,relation:string,resource_key:string,scope:string}> */
            public array $revocations = [];

            public bool $revokeReturn = false;

            public function listRelationships(array $filters = []): array
            {
                $this->listFilters[] = $filters;

                return $this->listResults;
            }

            public function revokeRelationshipByKey(
                string $principalId,
                string $relation,
                string $resourceKey,
                \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL,
            ): bool {
                $this->revocations[] = [
                    'principal_id' => $principalId,
                    'relation' => $relation,
                    'resource_key' => $resourceKey,
                    'scope' => (string) ($scope instanceof \Quantum\Authorization\Authority\Scope ? $scope : new \Quantum\Authorization\Authority\Scope($scope)),
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
