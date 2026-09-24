<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Exceptions\AuthorizationDeniedException;
use Quantum\Authorization\Exceptions\AuthorizationEvaluationException;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Authorization\Policy\Attributes\PolicyFor;
use Quantum\Authorization\Policy\PolicyRegistry;
use Quantum\Authorization\Principal\Principal;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationManagerTest extends TestCase
{
    public function test_authorization_manager_denies_by_default_when_no_evaluators_match(): void
    {
        $app = new Application(sys_get_temp_dir());
        $manager = $app->make(AuthorizationManagerInterface::class);

        self::assertFalse($manager->check('posts.update'));

        $decision = $manager->decide('posts.update');

        self::assertTrue($manager->cannot('posts.update'));
        self::assertFalse($decision->isAllowed());
        self::assertSame('deny_by_default', $decision->reasonCode());
    }

    public function test_authorization_manager_evaluates_defined_gates_and_bound_principals(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(GateRegistry::class)->define('dashboard.view', static function (PrincipalInterface $principal): bool {
            return $principal->authenticated() && $principal->id() === '42';
        });

        $manager = $app->make(AuthorizationManagerInterface::class);
        $bound = $manager->for(new Principal('42'));

        self::assertTrue($bound->check('dashboard.view'));
        self::assertFalse($manager->check('dashboard.view'));
    }

    public function test_authorization_manager_dispatches_policies_using_the_ability_method(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(PolicyRegistry::class)->register(AuthorizationArticle::class, new AuthorizationArticlePolicy());

        $manager = $app->make(AuthorizationManagerInterface::class);

        self::assertTrue($manager->check(
            'update',
            new AuthorizationArticle(7),
            principal: new Principal('7'),
        ));
        self::assertFalse($manager->check(
            'update',
            new AuthorizationArticle(7),
            principal: new Principal('9'),
        ));
    }

    public function test_authorize_throws_a_denied_exception_for_non_allow_results(): void
    {
        $app = new Application(sys_get_temp_dir());

        $this->expectException(AuthorizationDeniedException::class);

        $app->make(AuthorizationManagerInterface::class)->authorize('reports.export');
    }

    public function test_policy_registry_registers_attribute_declared_policies_from_config_lists(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(PolicyRegistry::class)->registerFromConfig([
            AttributeConfiguredArticlePolicy::class,
        ]);

        $manager = $app->make(AuthorizationManagerInterface::class);

        self::assertTrue($manager->check(
            'publish',
            new AuthorizationArticle(7),
            principal: new Principal('7'),
        ));
        self::assertFalse($manager->check(
            'publish',
            new AuthorizationArticle(7),
            principal: new Principal('9'),
        ));
    }

    public function test_authorization_manager_can_allow_by_default_when_strategy_is_configured(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization', [
            'default_strategy' => 'allow',
            'fail_closed' => true,
            'abilities' => [],
            'policies' => [],
        ]);

        $manager = $app->make(AuthorizationManagerInterface::class);
        $decision = $manager->decide('reports.preview');

        self::assertTrue($decision->isAllowed());
        self::assertSame('allow_by_default', $decision->reasonCode());
    }

    public function test_authorization_manager_can_fail_open_when_evaluator_throws(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization', [
            'default_strategy' => 'allow',
            'fail_closed' => false,
            'abilities' => [],
            'policies' => [],
        ]);
        $app->make(GateRegistry::class)->define('reports.preview', static function (): bool {
            throw new \RuntimeException('planner exploded');
        });

        $manager = $app->make(AuthorizationManagerInterface::class);
        $decision = $manager->decide('reports.preview');

        self::assertTrue($decision->isAllowed());
        self::assertSame('all_evaluators_abstained_allow_by_default', $decision->reasonCode());
    }

    public function test_authorization_manager_fails_closed_when_evaluator_throws(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization', [
            'default_strategy' => 'deny',
            'fail_closed' => true,
            'abilities' => [],
            'policies' => [],
        ]);
        $app->make(GateRegistry::class)->define('reports.preview', static function (): bool {
            throw new \RuntimeException('planner exploded');
        });

        $manager = $app->make(AuthorizationManagerInterface::class);
        $decision = $manager->decide('reports.preview');

        self::assertTrue($decision->isFailure());
        self::assertSame('authorization_evaluation_failed_fail_closed', $decision->reasonCode());

        $this->expectException(AuthorizationEvaluationException::class);
        $manager->authorize('reports.preview');
    }
}

final readonly class AuthorizationArticle
{
    public function __construct(public int $ownerId)
    {
    }
}

final class AuthorizationArticlePolicy
{
    public function update(PrincipalInterface $principal, AuthorizationArticle $article): bool
    {
        return $principal->id() === (string) $article->ownerId;
    }
}

#[PolicyFor(AuthorizationArticle::class)]
final class AttributeConfiguredArticlePolicy
{
    public function publish(PrincipalInterface $principal, AuthorizationArticle $article): bool
    {
        return $principal->id() === (string) $article->ownerId;
    }
}
