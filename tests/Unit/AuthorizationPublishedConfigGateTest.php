<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationConsistencyReportCommand;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Authorization\Console\Commands\AuthorizationManifestCompileCommand;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;
use Quantum\Routing\RouteCollection;
use VoltStack\Framework\Application;

final class AuthorizationPublishedConfigGateTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volt-authz-published-gate-' . uniqid('', true);

        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'bootstrap', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Authorization Published Gate',
    'env' => 'testing',
    'providers' => [],
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'authorization.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'default_driver' => 'in_memory',
    'drivers' => [
        'in_memory' => [],
    ],
    'consistency' => [
        'default' => 'in_memory',
        'drivers' => [
            'in_memory' => [],
        ],
    ],
    'manifest' => [
        'default' => 'in_memory',
        'drivers' => [
            'in_memory' => [],
        ],
    ],
];
PHP
        );

        $this->writeBootstrapApp();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_manifest_compile_without_gate_still_works_when_no_published_config_exists(): void
    {
        $this->bootstrapAuthzInfrastructure();

        $command = new AuthorizationManifestCompileCommand($this->basePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile']),
            $output,
        );

        self::assertSame(0, $exit);
    }

    public function test_manifest_compile_gate_passes_when_published_config_matches_effective(): void
    {
        $this->bootstrapAuthzInfrastructure();
        $this->publishCurrentConfiguration();

        $command = new AuthorizationManifestCompileCommand($this->basePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--require-published-config']),
            $output,
        );

        self::assertSame(0, $exit);
    }

    public function test_manifest_compile_gate_fails_when_no_active_generation_exists(): void
    {
        $this->bootstrapAuthzInfrastructure();

        $command = new AuthorizationManifestCompileCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--require-published-config']),
            $output,
        );
    }

    public function test_manifest_compile_gate_fails_when_published_config_has_drift(): void
    {
        $this->bootstrapAuthzInfrastructure();
        $this->publishCurrentConfiguration();
        $this->writeBootstrapApp(mutateAfterBoot: true);

        $command = new AuthorizationManifestCompileCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('effective snapshot differs from the active generation');

        $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--require-published-config']),
            $output,
        );
    }

    public function test_consistency_report_gate_propagates_without_being_swallowed_by_bootstrap_catch(): void
    {
        $this->bootstrapAuthzInfrastructure();

        $command = new AuthorizationConsistencyReportCommand($this->basePath);
        $output = new Output();

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('no active configuration generation exists');

        $command->handle(
            Input::fromArgv(['volt', 'authz:consistency:report', '--require-published-config', '--json']),
            $output,
        );
    }

    private function bootstrapAuthzInfrastructure(): void
    {
        // No es necesario ejecutar esta instancia; solo sirve de documentacion.
        // El bootstrap/app.php devuelto por writeBootstrapApp registra el servicio de rutas vacio
        // y la infraestructura minima; Application + Bootstrapper lo llenan.
        // Forzamos una instancia bootstrapped aqui para registrar el storage path y
        // asegurar que los directorios de publication existen antes de publicarla.
        $app = new Application($this->basePath);
        $bootstrapper = new \Quantum\Bootstrap\Bootstrapper($app);
        $bootstrapper->loadConfiguration();
        $app->instance(RouteCollection::class, new RouteCollection());
        $app->instance(
            AuthorizationManifestStoreInterface::class,
            new class implements AuthorizationManifestStoreInterface {
                /** @var list<AuthorizationMetadataPayload> */
                public array $written = [];

                public function has(string $fingerprint): bool { return false; }

                public function get(string $fingerprint): ?AuthorizationMetadataPayload { return null; }

                public function put(AuthorizationMetadataPayload $payload): void { $this->written[] = $payload; }

                public function forget(string $fingerprint): void {}

                public function clear(): int { return 0; }
            },
        );
        $app->instance(
            AuthorizationConsistencyInterface::class,
            new class implements AuthorizationConsistencyInterface {
                public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string { return 'v1'; }

                public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string { return 'v1'; }

                public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array { return []; }

                public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array { return []; }

                public function inspect(): array { return []; }
            },
        );
    }

    private function publishCurrentConfiguration(): string
    {
        $app = new Application($this->basePath);
        $bootstrapper = new \Quantum\Bootstrap\Bootstrapper($app);
        $bootstrapper->loadConfiguration();

        $repository = $app->make(ConfigRepository::class);
        $repository->set('app.key', new SecretReference('APP_KEY'));

        $codec = $app->configSnapshotCodec();
        $baseSnapshot = $repository->snapshot(provenance: $repository->provenance());
        $publishedSnapshot = $repository->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        );

        $artifact = $app->configManifestStore()->publish($publishedSnapshot);
        $app->configManifestStore()->activateGeneration($artifact->generationId());

        return $artifact->generationId();
    }

    private function writeBootstrapApp(bool $mutateAfterBoot = false): void
    {
        $escapedBasePath = var_export($this->basePath, true);
        $mutation = $mutateAfterBoot
            ? "\n\$app->make(\\Quantum\\Config\\ConfigRepository::class)->set('authorization.default_driver', 'filesystem');"
            : '';

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

declare(strict_types=1);

use Quantum\Bootstrap\Bootstrapper;
use VoltStack\Framework\Application;

\$app = new Application({$escapedBasePath});
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();
\$app->boot();{$mutation}

return \$app;
PHP
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
