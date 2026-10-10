<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyInvalidateCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationConsistencyInvalidateCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_consistency_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_consistency_test_app'] = null;
        unset($GLOBALS['__volt_authz_consistency_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationConsistencyInvalidateCommand($this->tempBasePath);

        self::assertSame('authz:consistency:invalidate', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:consistency:invalidate', $command->aliases());
        self::assertContains('authz:invalidate-consistency', $command->aliases());
    }

    public function test_command_rejects_invalid_domain(): void
    {
        $command = new AuthorizationConsistencyInvalidateCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:invalidate',
            '--domain=invalid',
        ]), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('authority, relationships o all', $output->stderr());
    }

    public function test_command_invalidates_both_domains_and_can_emit_json(): void
    {
        $spy = new class implements AuthorizationConsistencyInterface {
            /** @var list<array{principal_id:?string,scope:string|null,reason:string|null}> */
            public array $authorityCalls = [];

            /** @var list<array{principal_id:?string,scope:string|null,reason:string|null}> */
            public array $relationshipCalls = [];

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
                $this->authorityCalls[] = [
                    'principal_id' => $principalId,
                    'scope' => is_string($scope) ? $scope : ($scope instanceof \Quantum\Authorization\Authority\Scope ? (string) $scope : null),
                    'reason' => $reason,
                ];

                return ['scope' => 'v2'];
            }

            public function invalidateRelationships(
                ?string $principalId = null,
                \Quantum\Authorization\Authority\Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                $this->relationshipCalls[] = [
                    'principal_id' => $principalId,
                    'scope' => is_string($scope) ? $scope : ($scope instanceof \Quantum\Authorization\Authority\Scope ? (string) $scope : null),
                    'reason' => $reason,
                ];

                return ['principal_scope' => 'v3'];
            }

            public function inspect(): array
            {
                return [
                    'implementation' => self::class,
                    'last_bump_at' => null,
                    'bump_counters_by_segment' => [],
                ];
            }
        };

        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyInvalidateCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:invalidate',
            '--domain=all',
            '--principal-id=u_1',
            '--scope=tenant:acme',
            '--reason=manual',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertSame([['principal_id' => 'u_1', 'scope' => 'tenant:acme', 'reason' => 'manual']], $spy->authorityCalls);
        self::assertSame([['principal_id' => 'u_1', 'scope' => 'tenant:acme', 'reason' => 'manual']], $spy->relationshipCalls);
        self::assertStringContainsString('"authority"', $output->stdout());
        self::assertStringContainsString('"relationships"', $output->stdout());
        self::assertStringContainsString('"reason":', $output->stdout());
        self::assertStringContainsString('"manual"', $output->stdout());
        self::assertStringContainsString('"inspect"', $output->stdout());
    }

    public function test_command_can_invalidate_single_domain_with_verbose_output(): void
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
                return ['global' => 'v2'];
            }

            public function invalidateRelationships(
                ?string $principalId = null,
                \Quantum\Authorization\Authority\Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                throw new \RuntimeException('should not be called');
            }

            public function inspect(): array
            {
                return [];
            }
        };

        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyInvalidateCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:invalidate',
            '--domain=authority',
            '--verbose',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Driver de consistencia', $output->stdout());
        self::assertStringContainsString('authority', $output->stdout());
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_consistency_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_consistency_test_app' . "'" . '];' . PHP_EOL,
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
