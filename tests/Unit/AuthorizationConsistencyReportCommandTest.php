<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyReportCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationConsistencyReportCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_consistency_report_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_consistency_report_test_app'] = null;
        unset($GLOBALS['__volt_authz_consistency_report_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationConsistencyReportCommand($this->tempBasePath);

        self::assertSame('authz:consistency:report', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:consistency:report', $command->aliases());
        self::assertContains('authz:report-consistency', $command->aliases());
    }

    public function test_command_emits_json_report_for_specific_principal_and_scope(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.consistency.driver', 'file');
        $app->make(\Quantum\Config\ConfigRepository::class)->set(
            'authorization.consistency.file.path',
            $this->tempBasePath . DIRECTORY_SEPARATOR . 'shared',
        );
        $this->writeBootstrap($app);

        /** @var AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(AuthorizationConsistencyInterface::class);
        $consistency->invalidateAuthority('u_1', 'tenant:acme');
        $consistency->invalidateRelationships('u_1', 'tenant:acme');

        $command = new AuthorizationConsistencyReportCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:report',
            '--principal-id=u_1',
            '--scope=tenant:acme',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('"authority"', $output->stdout());
        self::assertStringContainsString('"relationships"', $output->stdout());
        self::assertStringContainsString('"backend"', $output->stdout());
        self::assertStringContainsString('"principal_scope"', $output->stdout());
    }

    public function test_command_can_render_verbose_human_output(): void
    {
        $spy = new class implements AuthorizationConsistencyInterface {
            public function authorityVersion(string $principalId, \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function relationshipVersion(string $principalId, \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function invalidateAuthority(
                ?string $principalId = null,
                \Quantum\Authorization\Authority\Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                return [];
            }

            public function invalidateRelationships(
                ?string $principalId = null,
                \Quantum\Authorization\Authority\Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                return [];
            }

            public function inspect(): array
            {
                return [
                    'version_authority_info' => [
                        'kind' => 'generic',
                    ],
                    'bump_counters_by_segment' => [
                        'authority.global' => 2,
                        'relationships.principal' => 1,
                    ],
                ];
            }
        };

        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyReportCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:report',
            '--principal-id=u_1',
            '--scope=tenant:acme',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Driver de consistencia', $output->stdout());
        self::assertStringContainsString('Reporte de consistencia Authorization', $output->stdout());
        self::assertStringContainsString('Backend details', $output->stdout());
        self::assertStringContainsString('Bump counters', $output->stdout());
        self::assertStringContainsString('authority.global', $output->stdout());
    }

    public function test_json_report_includes_inspect_and_last_bump_fields_for_file_backend(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.consistency.driver', 'file');
        $app->make(\Quantum\Config\ConfigRepository::class)->set(
            'authorization.consistency.file.path',
            $this->tempBasePath . DIRECTORY_SEPARATOR . 'shared',
        );
        $this->writeBootstrap($app);

        /** @var \Quantum\Authorization\Contracts\AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(\Quantum\Authorization\Contracts\AuthorizationConsistencyInterface::class);
        $consistency->invalidateAuthority('u_1', 'tenant:acme', 'admin change');

        $command = new AuthorizationConsistencyReportCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:report',
            '--principal-id=u_1',
            '--scope=tenant:acme',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);

        $decoded = json_decode($output->stdout(), true);
        self::assertIsArray($decoded);
        self::assertSame('u_1', $decoded['principal_id']);
        self::assertSame('tenant:acme', $decoded['scope']);
        self::assertArrayHasKey('inspect', $decoded);
        self::assertArrayHasKey('backend', $decoded);
        self::assertArrayHasKey('last_bump_at', $decoded);
        self::assertNotEmpty($decoded['last_bump_at']);
        self::assertSame('file', $decoded['inspect']['version_authority_info']['kind'] ?? null);
        self::assertArrayHasKey('authority.principal', $decoded['inspect']['bump_counters_by_segment'] ?? []);
        self::assertArrayHasKey('authority.scope', $decoded['inspect']['bump_counters_by_segment'] ?? []);
        self::assertSame('admin change', $decoded['inspect']['last_bump_reasons_by_segment']['authority.principal'] ?? null);
        self::assertSame('admin change', $decoded['inspect']['last_bump_reasons_by_segment']['authority.scope'] ?? null);
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_consistency_report_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_consistency_report_test_app' . "'" . '];' . PHP_EOL,
        );
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
