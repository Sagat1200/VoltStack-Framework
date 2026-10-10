<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Context\ScopeManager;

final class ScopeManagerConcurrentSlotTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        RuntimeContextTestHelper::resetStack();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-scope-manager-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack Scope Manager',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        RuntimeContextTestHelper::resetStack();

        parent::tearDown();
    }

    public function test_two_scope_managers_run_concurrent_units_without_sharing_runtime_context(): void
    {
        $app = new Application($this->basePath);
        $managerA = new ScopeManager($app);
        $managerB = new ScopeManager($app);

        $requestA = Request::create('/a', 'GET');
        $contextA = $managerA->beginRequest($requestA);

        $requestB = Request::create('/b', 'GET');
        $contextB = $managerB->beginRequest($requestB);

        // Cada manager conserva su propio contexto local aunque el stack global de RuntimeContext tenga a B arriba
        self::assertSame($contextA, $managerA->current());
        self::assertSame($contextB, $managerB->current());
        self::assertNotSame($contextA, $contextB);
        self::assertSame('/a', $managerA->current()?->request()?->path());
        self::assertSame('/b', $managerB->current()?->request()?->path());

        // El scope activo en el app pertenece a B (último begin) y así queda su scoped Request
        self::assertSame($requestB, $app->make(Request::class));

        // Cada manager, al end(), desactiva su propio contexto y limpia su referencia local.
        // Si quedan otros managers activos, RuntimeContext::current() refleja el tope del stack,
        // pero el manager que invocó end() ya no debe retener la referencia a su propio contexto cerrado.
        $contextBReference = $contextB;
        $managerB->end();
        $currentAfterB = $managerB->current();
        // Como managerA sigue abierto, el stack global tiene a A arriba.
        self::assertNotSame($contextBReference, $currentAfterB);
        // Al salir de B, managerA sigue teniendo su propio contexto local correcto
        self::assertSame($contextA, $managerA->current());
        self::assertSame('/a', $managerA->current()?->request()?->path());

        $managerA->end();
        // Tras cerrar todos los managers de esta prueba, el stack global queda limpio
        self::assertNull(RuntimeContext::current());
        self::assertNull($managerA->current());
        self::assertNull($managerB->current());
    }

    public function test_scope_manager_re_enter_creates_fresh_execution_slot_and_scope(): void
    {
        $app = new Application($this->basePath);
        $manager = new ScopeManager($app);

        $firstRequest = Request::create('/first', 'GET');
        $firstContext = $manager->beginRequest($firstRequest);
        $firstScopeId = $app->currentScopeId();

        // Re-enter sin end explícito (debe desactivar slot anterior)
        $secondRequest = Request::create('/second', 'GET');
        $secondContext = $manager->beginRequest($secondRequest);

        self::assertNotSame($firstContext, $secondContext);
        self::assertNotSame($firstScopeId, $app->currentScopeId());
        self::assertSame($secondContext, $manager->current());
        self::assertSame('/second', $app->make(Request::class)->path());

        // Context anterior ya no está activo globalmente (no es el current del stack)
        self::assertSame($secondContext, RuntimeContext::current());

        $manager->end();
        self::assertNull($manager->current());
    }

    public function test_scope_manager_retains_request_kind_metadata_across_command_and_job_units(): void
    {
        $app = new Application($this->basePath);
        $manager = new ScopeManager($app);

        $commandContext = $manager->beginCommand('list-users');
        self::assertSame('command', $commandContext->metadata()['runtime.scope_kind'] ?? null);
        self::assertSame('cli', $commandContext->metadata()['runtime.channel'] ?? null);
        self::assertSame('list-users', $commandContext->metadata()['runtime.unit_name'] ?? null);
        self::assertSame('command', $app->currentScopeKind());
        $manager->end();

        $jobContext = $manager->beginJob('send-welcome');
        self::assertSame('job', $jobContext->metadata()['runtime.scope_kind'] ?? null);
        self::assertSame('worker', $jobContext->metadata()['runtime.channel'] ?? null);
        self::assertSame('send-welcome', $jobContext->metadata()['runtime.unit_name'] ?? null);
        self::assertSame('job', $app->currentScopeKind());
        $manager->end();
    }

    public function test_scope_manager_publishes_scoped_instances_of_request_and_runtime_context(): void
    {
        $app = new Application($this->basePath);
        $manager = new ScopeManager($app);

        $request = Request::create('/ping', 'POST');
        $context = $manager->beginRequest($request);

        self::assertSame($request, $app->make(Request::class));
        self::assertSame($context, $app->make(RuntimeContext::class));

        $manager->end();
    }

    public function test_scope_manager_run_in_job_ensures_cleanup_even_on_exception(): void
    {
        $app = new Application($this->basePath);
        $manager = new ScopeManager($app);

        try {
            $manager->runInJob(static function () {
                throw new \RuntimeException('boom');
            }, 'failing-job');
            self::fail('Expected exception from callback');
        } catch (\RuntimeException) {
            // Esperado
        }

        // Después del finally del ScopeManager, no debe quedar contexto pendiente ni scope activo
        self::assertNull($manager->current());
        self::assertFalse($app->hasActiveScope());
    }

    /**
     * @param string $path
     */
    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $children = scandir($path);
        if ($children === false) {
            return;
        }

        foreach ($children as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $child;
            if (is_dir($childPath)) {
                $this->deleteDirectory($childPath);
            } else {
                unlink($childPath);
            }
        }

        rmdir($path);
    }
}
