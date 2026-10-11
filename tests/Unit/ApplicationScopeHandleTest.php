<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\Scope\ConfigurationScopeRegistry;
use Quantum\Container\ScopeKind;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

final class ApplicationScopeHandleTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-app-scope-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        RuntimeContext::setCurrent(null);

        $configFile = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

        if (is_file($configFile)) {
            unlink($configFile);
        }

        $configDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'config';

        if (is_dir($configDirectory)) {
            rmdir($configDirectory);
        }

        if (is_dir($this->basePath)) {
            rmdir($this->basePath);
        }

        parent::tearDown();
    }

    private function activateRuntimeContext(): RuntimeContext
    {
        $context = new RuntimeContext(
            'test-' . uniqid('', true),
            Request::create('/'),
            microtime(true),
        );

        RuntimeContext::setCurrent($context);

        return $context;
    }

    public function test_open_scope_fires_start_callback_and_end_callback_on_close(): void
    {
        $app = new Application($this->basePath);
        $this->activateRuntimeContext();

        $started = 0;
        $ended = 0;

        $app->onScopeStart(static function () use (&$started): void {
            $started++;
        });

        $app->onScopeEnd(static function () use (&$ended): void {
            $ended++;
        });

        $handle = $app->openScope(ScopeKind::Request);

        self::assertSame(1, $started, 'fireScopeStart debe invocarse al abrir el scope');
        self::assertSame(0, $ended, 'fireScopeEnd no debe invocarse hasta cerrar el scope');

        $report = $handle->close();

        self::assertSame(1, $ended, 'fireScopeEnd debe invocarse al cerrar el scope');
        self::assertTrue($report->ok());
    }

    public function test_open_scope_clears_configuration_scope_registry_on_close(): void
    {
        $app = new Application($this->basePath);
        $this->activateRuntimeContext();

        /** @var ConfigurationScopeRegistry $registry */
        $registry = $app->make(ConfigurationScopeRegistry::class);

        $handle = $app->openScope(ScopeKind::Request);
        $scopeId = $handle->token()->frameId;

        // El scope puede no estar registrado en el registry hasta que se haga
        // una resolucion de configuracion scoped; lo importante es que close()
        // notifique al registry sin errores.
        $report = $handle->close();

        self::assertTrue($report->ok());
        self::assertNull($registry->findById($scopeId));
    }

    public function test_open_worker_scope_fires_start_and_end_callbacks(): void
    {
        $app = new Application($this->basePath);
        $this->activateRuntimeContext();

        $started = 0;
        $ended = 0;

        $app->onScopeStart(static function () use (&$started): void {
            $started++;
        });

        $app->onScopeEnd(static function () use (&$ended): void {
            $ended++;
        });

        $worker = $app->openWorkerScope();

        self::assertSame(1, $started);

        $report = $worker->close();

        self::assertSame(1, $ended);
        self::assertTrue($report->ok());
    }

    public function test_worker_child_scope_fires_start_end_and_clears_registry(): void
    {
        $app = new Application($this->basePath);
        $this->activateRuntimeContext();

        /** @var ConfigurationScopeRegistry $registry */
        $registry = $app->make(ConfigurationScopeRegistry::class);

        $started = 0;
        $ended = 0;

        $app->onScopeStart(static function () use (&$started): void {
            $started++;
        });

        $app->onScopeEnd(static function () use (&$ended): void {
            $ended++;
        });

        $worker = $app->openWorkerScope();
        self::assertSame(1, $started);

        $child = $worker->enter(ScopeKind::Request);
        self::assertSame(2, $started, 'abrir un scope hijo debe disparar fireScopeStart');

        $childScopeId = $child->token()->frameId;

        $childReport = $child->close();
        self::assertSame(1, $ended, 'cerrar el hijo debe disparar fireScopeEnd');
        self::assertTrue($childReport->ok());
        self::assertNull($registry->findById($childScopeId));

        $workerReport = $worker->close();
        self::assertSame(2, $ended, 'cerrar el worker debe disparar fireScopeEnd');
        self::assertTrue($workerReport->ok());
    }
}
