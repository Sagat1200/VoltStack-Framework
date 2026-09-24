<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Facades\Authorization;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;

final class AuthorizationIntegrationTest extends TestCase
{
    public function test_authorization_helpers_and_facade_use_the_registered_manager(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(GateRegistry::class)->define('dashboard.view', static function (PrincipalInterface $principal): bool {
            return $principal->authenticated();
        });

        $router = $app->make(Router::class);
        $router->get('/helpers', function (): array {
            auth()->setUser(['id' => 15]);

            return [
                'can' => can('dashboard.view'),
                'cannot' => cannot('dashboard.view'),
                'facade' => Authorization::check('dashboard.view'),
            ];
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/helpers'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['can']);
        self::assertFalse($payload['cannot']);
        self::assertTrue($payload['facade']);
    }

    public function test_authorization_resolves_the_principal_from_auth_without_leaking_between_requests(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(GateRegistry::class)->define('dashboard.view', static function (PrincipalInterface $principal): bool {
            return $principal->authenticated();
        });

        $router = $app->make(Router::class);
        $router->get('/signed-in', function (): array {
            auth()->setUser(['id' => 81]);

            return ['allowed' => can('dashboard.view')];
        });
        $router->get('/guest', fn (): array => ['allowed' => can('dashboard.view')]);

        $kernel = $app->make(HttpKernel::class);

        $signedInResponse = $kernel->handle(Request::create('/signed-in'));
        $guestResponse = $kernel->handle(Request::create('/guest'));

        /** @var array<string, mixed> $signedInPayload */
        $signedInPayload = json_decode($signedInResponse->content(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $guestPayload */
        $guestPayload = json_decode($guestResponse->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($signedInPayload['allowed']);
        self::assertFalse($guestPayload['allowed']);
    }
}
