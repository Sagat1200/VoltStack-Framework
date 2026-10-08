<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Principal\Principal;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class AuthorizationManagerDatabaseRelationshipTest extends TestCase
{
    private string $basePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-authz-rel-manager-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'relationships.sqlite';
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_authorization_manager_applies_database_relationship_driver_end_to_end(): void
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);
        $config->set('authorization.authority.evaluate_requirements_concretely', true);
        $config->set('authorization.relationships.evaluate', true);
        $config->set('authorization.relationships.driver', 'database');
        $config->set('authorization.authority.grants', [
            ['principal_id' => '42', 'permissions' => ['documents.manage']],
        ]);

        $document = (object) ['id' => 'doc-1'];
        $this->seedRelationshipTable($app->make(DatabaseInterface::class), [
            ['principal_id' => '42', 'relation' => 'owner', 'resource_key' => \stdClass::class . ':doc-1', 'scope' => 'global'],
        ]);

        $manager = $app->make(AuthorizationManagerInterface::class);
        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/policies/relation/database/{document}',
            static fn (): string => 'ok',
        ));
        $route->authorizeRelated('documents.manage', 'owner', 'document');

        $allowContext = new AuthorizationContext('req-owner-db', attributes: [
            'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
        ]);
        $denyContext = new AuthorizationContext('req-non-owner-db', attributes: [
            'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
        ]);

        $allow = $manager->decide('documents.manage', $document, $allowContext, new Principal('42'));
        $deny = $manager->decide('documents.manage', $document, $denyContext, new Principal('24'));

        self::assertTrue($allow->isAllowed());
        self::assertSame('manifest_requirement_granted_by_authority', $allow->reasonCode());
        self::assertTrue($allow->metadata()['relationship_evaluated'] ?? false);
        self::assertSame('owner', $allow->metadata()['relation'] ?? null);

        self::assertTrue($deny->isDenied());
        self::assertSame('manifest_requirement_relationship_not_satisfied', $deny->reasonCode());
        self::assertSame('owner', $deny->metadata()['relation'] ?? null);

        $app->make(DatabaseInterface::class)->connection()->disconnect();
    }

    /**
     * @param list<array{principal_id:string,relation:string,resource_key:string,scope:string}> $entries
     */
    private function seedRelationshipTable(DatabaseInterface $database, array $entries): void
    {
        $pdo = $database->connection()->pdo();
        $pdo->exec('CREATE TABLE authorization_relationships (principal_id TEXT NOT NULL, relation TEXT NOT NULL, resource_key TEXT NOT NULL, scope TEXT NOT NULL)');

        $statement = $pdo->prepare('INSERT INTO authorization_relationships (principal_id, relation, resource_key, scope) VALUES (:principal_id, :relation, :resource_key, :scope)');

        foreach ($entries as $entry) {
            $statement->execute([
                ':principal_id' => $entry['principal_id'],
                ':relation' => $entry['relation'],
                ':resource_key' => $entry['resource_key'],
                ':scope' => $entry['scope'],
            ]);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
