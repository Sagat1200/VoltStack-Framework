<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Core\BoundAuthorization;
use Quantum\Authorization\Core\ImpersonationPrincipalBuilder;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Authorization\Authority\Scope;
use VoltStack\Framework\Application;

final class AuthorizationImpersonationTest extends TestCase
{
    public function test_impersonation_principal_builder_emits_impersonated_user_type_with_stable_claims(): void
    {
        $builder = new ImpersonationPrincipalBuilder();

        $principal = $builder->build('admin_1', 'user_42', 'tenant:acme');

        self::assertSame(PrincipalType::ImpersonatedUser, $principal->type());
        self::assertSame('user_42', $principal->id());
        self::assertTrue($principal->authenticated());

        $claims = $principal->claims();
        self::assertSame('admin_1', $claims['originator_principal_id'] ?? null);
        self::assertSame('user_42', $claims['target_principal_id'] ?? null);
        self::assertSame('user_42', $claims['acting_as'] ?? null);
        self::assertSame('tenant:acme', $claims['impersonation_scope'] ?? null);
        self::assertArrayHasKey('impersonated_at', $claims);
    }

    public function test_impersonation_principal_builder_supports_duck_typing_ids(): void
    {
        $builder = new ImpersonationPrincipalBuilder();

        // Object with getId()
        $callerObj = new class {
            public function getId(): string
            {
                return 'caller-from-getId';
            }
        };
        // Object with id property
        $targetObj = new class {
            public string $id = 'target-from-property';
        };

        $principal = $builder->build($callerObj, $targetObj);
        self::assertSame('caller-from-getId', $principal->claims()['originator_principal_id'] ?? null);
        self::assertSame('target-from-property', $principal->claims()['target_principal_id'] ?? null);
    }

    public function test_impersonation_principal_builder_rejects_empty_ids(): void
    {
        $builder = new ImpersonationPrincipalBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $builder->build('', 'some-target');
    }

    public function test_impersonation_principal_builder_accepts_principal_interface_directly(): void
    {
        $builder = new ImpersonationPrincipalBuilder();

        $caller = new Principal('from-interface-caller', PrincipalType::User, true);
        $target = new Principal('from-interface-target', PrincipalType::User, true);

        $principal = $builder->build($caller, $target);

        self::assertSame('from-interface-caller', $principal->claims()['originator_principal_id'] ?? null);
        self::assertSame('from-interface-target', $principal->claims()['target_principal_id'] ?? null);
    }

    public function test_manager_impersonate_returns_bound_authorization_with_context(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var \Quantum\Authorization\Contracts\AuthorizationManagerInterface $manager */
        $manager = $app->make(\Quantum\Authorization\Contracts\AuthorizationManagerInterface::class);

        $bound = $manager->impersonate('caller_99', 'target_7', 'tenant:alpha');

        self::assertInstanceOf(BoundAuthorization::class, $bound);
    }

    public function test_bound_authorization_context_is_merged_but_not_overwritten(): void
    {
        // Ensure BoundAuthorization still works with the original legacy 2-arg signature
        $manager = new class implements \Quantum\Authorization\Contracts\AuthorizationManagerInterface {
            public function for(mixed $principal): \Quantum\Authorization\Core\BoundAuthorization
            {
                return new \Quantum\Authorization\Core\BoundAuthorization($this, $principal);
            }

            public function impersonate(mixed $caller, mixed $target, \Quantum\Authorization\Authority\Scope|string|null $scope = null): \Quantum\Authorization\Core\BoundAuthorization
            {
                throw new \BadMethodCallException('unused');
            }

            public function check(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                $capturedPrincipal = is_string($principal) ? $principal : ($principal instanceof PrincipalInterface ? $principal->id() : '');
                $capturedAttr = $context?->attribute('authorization.impersonation.originator_id');

                return $capturedPrincipal === 'target_7' && $capturedAttr === 'caller_99';
            }

            public function cannot(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): bool
            {
                return ! $this->check($ability, $subject, $context, $principal);
            }

            public function decide(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                return $this->check($ability, $subject, $context, $principal)
                    ? \Quantum\Authorization\Decision\DecisionResult::allow('test', 'ok')
                    : \Quantum\Authorization\Decision\DecisionResult::deny('test', 'no');
            }

            public function authorize(string|\Quantum\Authorization\Ability\Ability $ability, mixed $subject = null, ?\Quantum\Authorization\Context\AuthorizationContext $context = null, mixed $principal = null): \Quantum\Authorization\Decision\DecisionResult
            {
                $decision = $this->decide($ability, $subject, $context, $principal);

                if ($decision->isDenied()) {
                    throw new \Quantum\Authorization\Exceptions\AuthorizationDeniedException($decision);
                }

                return $decision;
            }
        };

        $targetPrincipal = new Principal('target_7', PrincipalType::ImpersonatedUser, true, [
            'originator_principal_id' => 'caller_99',
            'target_principal_id' => 'target_7',
        ]);

        $bindContext = \Quantum\Authorization\Context\AuthorizationContext::empty()
            ->withAttributes(['authorization.impersonation.originator_id' => 'caller_99']);

        $bound = new BoundAuthorization($manager, $targetPrincipal, $bindContext);

        self::assertTrue($bound->check('some.ability'));
    }
}
