<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use VoltStack\Framework\Application;

/**
 * Prueba que el estado mutable del ORM (IdentityMap, UnitOfWork, EntityManager)
 * esta aislado por slot de ejecucion, de modo que dos ejecuciones interleavadas
 * no comparten entidades gestionadas ni contexto de persistencia.
 *
 * No requiere base de datos: usa {@see EntityManagerInterface::getReference()}
 * que registra un placeholder en el IdentityMap sin consultar la DB.
 */
final class OrmExecutionSlotIsolationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-orm-isolation-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

declare(strict_types=1);

return [
    'name' => 'VoltStack ORM Isolation',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_entity_manager_is_a_different_instance_per_execution_slot(): void
    {
        $app = new Application($this->basePath);

        $slotA = $app->activateExecutionState();
        $app->enterScope('request');
        $emA = $app->make(EntityManagerInterface::class);

        $slotB = $app->activateExecutionState();
        $app->enterScope('request');
        $emB = $app->make(EntityManagerInterface::class);

        self::assertNotSame($emA, $emB);

        $app->deactivateExecutionState($slotB);
        // Al volver a A, el mismo EntityManager sigue resuelto desde el scope frame de A
        $emAAgain = $app->make(EntityManagerInterface::class);
        self::assertSame($emA, $emAAgain);

        $app->deactivateExecutionState($slotA);
    }

    public function test_identity_map_is_isolated_between_execution_slots(): void
    {
        $app = new Application($this->basePath);

        $slotA = $app->activateExecutionState();
        $app->enterScope('request');
        $emA = $app->make(EntityManagerInterface::class);
        $refA = $emA->getReference(OrmIsolationUser::class, 1);

        $slotB = $app->activateExecutionState();
        $app->enterScope('request');
        $emB = $app->make(EntityManagerInterface::class);

        // B no conoce la referencia de A
        self::assertFalse($emB->contains($refA));

        // B obtiene su propio placeholder para el mismo ID
        $refB = $emB->getReference(OrmIsolationUser::class, 1);
        self::assertNotSame($refA, $refB);
        self::assertTrue($emB->contains($refB));
        self::assertFalse($emA->contains($refB));

        // Al volver a A, getReference devuelve el mismo placeholder original
        $app->deactivateExecutionState($slotB);
        $refAAgain = $emA->getReference(OrmIsolationUser::class, 1);
        self::assertSame($refA, $refAAgain);

        $app->deactivateExecutionState($slotA);
    }

    public function test_managed_entities_in_one_slot_do_not_leak_into_another(): void
    {
        $app = new Application($this->basePath);

        $slotA = $app->activateExecutionState();
        $app->enterScope('request');
        $emA = $app->make(EntityManagerInterface::class);
        $userA = $emA->getReference(OrmIsolationUser::class, 42);

        $slotB = $app->activateExecutionState();
        $app->enterScope('request');
        $emB = $app->make(EntityManagerInterface::class);

        // El EntityManager de B no contiene entidades gestionadas por A
        self::assertFalse($emB->contains($userA));

        // B puede gestionar su propia entidad con el mismo ID sin afectar a A
        $userB = $emB->getReference(OrmIsolationUser::class, 42);
        self::assertNotSame($userA, $userB);

        // Cada EM reporta el estado correcto para sus propias entidades
        self::assertTrue($emA->contains($userA));
        self::assertTrue($emB->contains($userB));
        self::assertFalse($emA->contains($userB));
        self::assertFalse($emB->contains($userA));

        $app->deactivateExecutionState($slotB);
        $app->deactivateExecutionState($slotA);
    }

    public function test_clearing_entity_manager_in_one_slot_does_not_affect_other_slot(): void
    {
        $app = new Application($this->basePath);

        $slotA = $app->activateExecutionState();
        $app->enterScope('request');
        $emA = $app->make(EntityManagerInterface::class);
        $userA = $emA->getReference(OrmIsolationUser::class, 7);

        $slotB = $app->activateExecutionState();
        $app->enterScope('request');
        $emB = $app->make(EntityManagerInterface::class);
        $userB = $emB->getReference(OrmIsolationUser::class, 7);

        // Limpiar B no debe afectar a A
        $emB->clear();
        self::assertFalse($emB->contains($userB));
        self::assertTrue($emA->contains($userA));

        // Al volver a A, su entidad sigue gestionada
        $app->deactivateExecutionState($slotB);
        self::assertTrue($emA->contains($userA));
        self::assertSame($userA, $emA->getReference(OrmIsolationUser::class, 7));

        $app->deactivateExecutionState($slotA);
    }

    public function test_get_reference_returns_cached_instance_within_same_slot(): void
    {
        $app = new Application($this->basePath);

        $slotA = $app->activateExecutionState();
        $app->enterScope('request');
        $emA = $app->make(EntityManagerInterface::class);

        $first = $emA->getReference(OrmIsolationUser::class, 99);
        $second = $emA->getReference(OrmIsolationUser::class, 99);

        // Dentro del mismo slot, el IdentityMap devuelve la misma instancia
        self::assertSame($first, $second);

        $app->deactivateExecutionState($slotA);
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

#[Entity]
final class OrmIsolationUser
{
    #[Id]
    public ?int $id = null;

    #[Column]
    public string $name = '';
}
