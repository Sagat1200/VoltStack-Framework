<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyFlushCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Console\Input;
use Quantum\Console\Output;
use VoltStack\Framework\Application;

final class AuthorizationConsistencyFlushCommandTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_flush_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_flush_test_app'] = null;
        unset($GLOBALS['__volt_authz_flush_test_app']);
        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationConsistencyFlushCommand($this->tempBasePath);

        self::assertSame('authz:consistency:flush', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertContains('authorization:consistency:flush', $command->aliases());
        self::assertContains('authz:flush-consistency', $command->aliases());
    }

    public function test_command_rejects_invalid_domain(): void
    {
        $command = new AuthorizationConsistencyFlushCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:flush',
            '--domain=invalid',
        ]), $output);

        self::assertSame(1, $exit);
        self::assertStringContainsString('authority, relationships o both', $output->stderr());
    }

    public function test_command_flushes_both_domains_and_emits_observable_json(): void
    {
        $spy = $this->makeSpy();

        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyFlushCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:flush',
            '--domain=both',
            '--principal=u_1',
            '--scope=tenant:acme',
            '--reason=manual.flush',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertCount(1, $spy->authorityCalls);
        self::assertSame('u_1', $spy->authorityCalls[0]['principal_id']);
        self::assertSame('manual.flush', $spy->authorityCalls[0]['reason']);
        self::assertCount(1, $spy->relationshipCalls);

        $stdout = $output->stdout();
        self::assertStringContainsString('"invalidated_segments"', $stdout);
        self::assertStringContainsString('"consistency_driver"', $stdout);
        self::assertStringContainsString('"flush_metrics"', $stdout);
        self::assertStringContainsString('"authority"', $stdout);
        self::assertStringContainsString('"relationships"', $stdout);
    }

    public function test_command_dry_run_does_not_invalidate(): void
    {
        $spy = $this->makeSpy();

        $app = new Application(sys_get_temp_dir());
        $app->instance(AuthorizationConsistencyInterface::class, $spy);
        $this->writeBootstrap($app);

        $command = new AuthorizationConsistencyFlushCommand($this->tempBasePath);
        $output = new Output();

        $exit = $command->handle(Input::fromArgv([
            'volt',
            'authz:consistency:flush',
            '--domain=authority',
            '--dry-run',
            '--json',
        ]), $output);

        self::assertSame(0, $exit);
        self::assertCount(0, $spy->authorityCalls);
        self::assertCount(0, $spy->relationshipCalls);

        $stdout = $output->stdout();
        self::assertStringContainsString('"would_flush"', $stdout);
        self::assertStringContainsString('"dry_run": true', $stdout);
    }

    private function makeSpy(): object
    {
        return new class implements AuthorizationConsistencyInterface {
            /** @var list<array{principal_id:?string,scope:mixed,reason:string|null}> */
            public array $authorityCalls = [];

            /** @var list<array{principal_id:?string,scope:mixed,reason:string|null}> */
            public array $relationshipCalls = [];

            public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function invalidateAuthority(
                ?string $principalId = null,
                Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                $this->authorityCalls[] = [
                    'principal_id' => $principalId,
                    'scope' => $scope,
                    'reason' => $reason,
                ];

                return ['principal@scope' => 'v2'];
            }

            public function invalidateRelationships(
                ?string $principalId = null,
                Scope|string|null $scope = null,
                ?string $reason = null,
            ): array {
                $this->relationshipCalls[] = [
                    'principal_id' => $principalId,
                    'scope' => $scope,
                    'reason' => $reason,
                ];

                return ['principal@scope' => 'v3'];
            }

            public function inspect(): array
            {
                return ['implementation' => self::class];
            }

            public function flushMetrics(): array
            {
                return [
                    'bump_counters_by_segment' => [],
                    'last_bump_reasons_by_segment' => [],
                    'last_bump_timestamps_by_segment' => [],
                    'total_bumps' => 0,
                ];
            }
        };
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_flush_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_flush_test_app' . "'" . '];' . PHP_EOL,
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
