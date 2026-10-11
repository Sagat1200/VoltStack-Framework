<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

/**
 * Verifica que el estado transversal (auth context) está aislado por slot de
 * ejecución y no filtra entre requests concurrentes.
 */
final class RuntimeCrossCuttingIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RuntimeContext::setCurrent(null);
    }

    protected function tearDown(): void
    {
        RuntimeContext::setCurrent(null);

        parent::tearDown();
    }

    public function test_authentication_context_is_isolated_between_slots(): void
    {
        $contextA = new RuntimeContext('req-a', Request::create('/a'), microtime(true));
        $contextB = new RuntimeContext('req-b', Request::create('/b'), microtime(true));

        $identityA = new GenericIdentity(new IdentityIdentifier('user-1001'), 'user');
        $identityB = new GenericIdentity(new IdentityIdentifier('user-2002'), 'user');

        $authContextA = new AuthenticationContext(
            identity: $identityA,
            reference: new IdentityReference($identityA->identifier(), $identityA->type()),
            requestId: 'req-a',
        );
        $authContextB = new AuthenticationContext(
            identity: $identityB,
            reference: new IdentityReference($identityB->identifier(), $identityB->type()),
            requestId: 'req-b',
        );

        $accessor = new AuthenticationContextAccessor();

        $slotA = RuntimeContext::activate($contextA);
        $accessor->put($authContextA);

        $slotB = RuntimeContext::activate($contextB);
        $accessor->put($authContextB);

        // En el slot B (tope del stack), el accessor debe devolver el contexto B.
        self::assertSame('user-2002', $accessor->get()?->identity->identifier()->value);

        // Al desactivar B, el tope es A; el accessor debe devolver el contexto A, no B.
        RuntimeContext::deactivate($slotB);
        self::assertSame('user-1001', $accessor->get()?->identity->identifier()->value);

        RuntimeContext::deactivate($slotA);
        self::assertNull(RuntimeContext::current());
    }

    public function test_authentication_context_clear_only_affects_current_slot(): void
    {
        $contextA = new RuntimeContext('req-a', Request::create('/a'), microtime(true));
        $contextB = new RuntimeContext('req-b', Request::create('/b'), microtime(true));

        $identityA = new GenericIdentity(new IdentityIdentifier('user-1001'), 'user');
        $identityB = new GenericIdentity(new IdentityIdentifier('user-2002'), 'user');

        $authContextA = new AuthenticationContext(
            identity: $identityA,
            reference: new IdentityReference($identityA->identifier(), $identityA->type()),
            requestId: 'req-a',
        );
        $authContextB = new AuthenticationContext(
            identity: $identityB,
            reference: new IdentityReference($identityB->identifier(), $identityB->type()),
            requestId: 'req-b',
        );

        $accessor = new AuthenticationContextAccessor();

        $slotA = RuntimeContext::activate($contextA);
        $accessor->put($authContextA);

        $slotB = RuntimeContext::activate($contextB);
        $accessor->put($authContextB);

        // Limpiar el contexto de B.
        $accessor->clear();
        self::assertNull($accessor->get());

        // Al volver a A, su contexto debe seguir intacto.
        RuntimeContext::deactivate($slotB);
        self::assertSame('user-1001', $accessor->get()?->identity->identifier()->value);

        RuntimeContext::deactivate($slotA);
    }

    public function test_no_cross_slot_leakage_when_slot_b_has_no_auth_context(): void
    {
        $contextA = new RuntimeContext('req-a', Request::create('/a'), microtime(true));
        $contextB = new RuntimeContext('req-b', Request::create('/b'), microtime(true));

        $identityA = new GenericIdentity(new IdentityIdentifier('user-1001'), 'user');
        $authContextA = new AuthenticationContext(
            identity: $identityA,
            reference: new IdentityReference($identityA->identifier(), $identityA->type()),
            requestId: 'req-a',
        );

        $accessor = new AuthenticationContextAccessor();

        $slotA = RuntimeContext::activate($contextA);
        $accessor->put($authContextA);

        // Activar B sin poner contexto de auth.
        $slotB = RuntimeContext::activate($contextB);

        // B no debe heredar el contexto de A.
        self::assertNull($accessor->get());

        RuntimeContext::deactivate($slotB);
        RuntimeContext::deactivate($slotA);
    }
}
