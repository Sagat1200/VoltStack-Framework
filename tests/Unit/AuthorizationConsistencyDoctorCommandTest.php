<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyDoctorCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationConsistencyDoctorCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_consistency_doctor_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_consistency_doctor_test_app'] = null;
        unset($GLOBALS['__volt_authz_consistency_doctor_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);

        self::assertSame('authz:consistency:doctor', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:consistency:doctor', $command->aliases());
        self::assertContains('authz:doctor-consistency', $command->aliases());
    }

    public function test_command_emits_json_doctor_report_with_backend_and_config(): void
    {
        $app = new Application($this->tempBasePath);
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.consistency.driver', 'cache');
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.consistency.cache.prefix', 'shared.authz.v');
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.consistency.cache.ttl_seconds', 3600);

        /** @var AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(AuthorizationConsistencyInterface::class);
        $consistency->invalidateAuthority('u_1', 'tenant:acme', 'bulk-sync');

        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:doctor',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        $decoded = json_decode($output->stdout(), true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['ok'] ?? null);
        self::assertTrue($decoded['is_versioned'] ?? null);
        self::assertSame('cache', $decoded['consistency_driver_config']['driver'] ?? null);
        self::assertSame('shared.authz.v', $decoded['consistency_driver_config']['cache']['prefix'] ?? null);
        self::assertSame(3600, $decoded['consistency_driver_config']['cache']['ttl_seconds'] ?? null);
        self::assertSame('cache', $decoded['inspect']['version_authority_info']['kind'] ?? null);
        self::assertSame('shared.authz.v', $decoded['inspect']['version_authority_info']['prefix'] ?? null);
        self::assertSame(1, $decoded['inspect']['bump_counters_by_segment']['authority.principal'] ?? null);
        self::assertSame('bulk-sync', $decoded['inspect']['last_bump_reasons_by_segment']['authority.principal'] ?? null);
    }

    public function test_command_emits_human_doctor_report_with_bump_counters_in_verbose(): void
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
                    'implementation' => self::class,
                    'namespace' => 'custom.ns',
                    'version_authority' => 'Fake\\Remote\\VersionAuthority',
                    'version_authority_info' => [
                        'kind' => 'remote',
                        'endpoint' => 'https://example.com/authz-versions',
                    ],
                    'last_bump_at' => '2026-01-02T03:04:05+00:00',
                    'bump_counters_by_segment' => [
                        'authority.global' => 4,
                        'relationships.scope' => 2,
                    ],
                    'last_bump_reasons_by_segment' => [
                        'authority.global' => 'secret rotation',
                    ],
                    'last_bump_timestamps_by_segment' => [
                        'authority.global' => '2026-01-02T03:04:05+00:00',
                    ],
                ];
            }
        };

        $app = new Application($this->tempBasePath);
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyDoctorCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:doctor',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Authorization consistency doctor', $output->stdout());
        self::assertStringContainsString('config.driver', $output->stdout());
        self::assertStringContainsString('version_authority_info:', $output->stdout());
        self::assertStringContainsString('remote', $output->stdout());
        self::assertStringContainsString('endpoint', $output->stdout());
        self::assertStringContainsString('last_bump_at: 2026-01-02T03:04:05+00:00', $output->stdout());
        self::assertStringContainsString('authority.global => 4 [reason: secret rotation]', $output->stdout());
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_consistency_doctor_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_consistency_doctor_test_app' . "'" . '];' . PHP_EOL,
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
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
