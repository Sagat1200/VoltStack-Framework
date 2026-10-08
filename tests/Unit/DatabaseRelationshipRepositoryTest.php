<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Relationship\DatabaseRelationshipRepository;
use Quantum\Cache\LocalVersionAuthority;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseInterface;
use VoltStack\Framework\Application;

final class DatabaseRelationshipRepositoryTest extends TestCase
{
    private string $basePath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-authz-rel-dbal-' . uniqid('', true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'relationships.sqlite';
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_database_repository_matches_scalar_object_and_hashed_resources(): void
    {
        $app = $this->makeApplication();
        $database = $app->make(DatabaseInterface::class);

        $document = (object) ['id' => 'doc-1'];
        $payload = ['kind' => 'invoice', 'id' => 7];
        $this->seedRelationshipTable($database, [
            ['principal_id' => 'u_1', 'relation' => 'owner', 'resource_key' => 'string:invoice-1', 'scope' => 'global'],
            ['principal_id' => 'u_1', 'relation' => 'editor', 'resource_key' => \stdClass::class . ':doc-1', 'scope' => 'tenant:acme'],
            ['principal_id' => 'u_1', 'relation' => 'viewer', 'resource_key' => sha1(json_encode($payload, JSON_THROW_ON_ERROR)), 'scope' => 'global'],
        ]);

        $repository = new DatabaseRelationshipRepository($database);

        self::assertTrue($repository->hasRelationship('u_1', 'owner', 'invoice-1'));
        self::assertTrue($repository->hasRelationship('u_1', 'editor', $document, 'tenant:acme:workspace:red'));
        self::assertTrue($repository->hasRelationship('u_1', 'viewer', $payload));
        self::assertFalse($repository->hasRelationship('u_1', 'owner', 'invoice-2'));

        $database->connection()->disconnect();
    }

    public function test_database_repository_honors_custom_table_name(): void
    {
        $app = $this->makeApplication();
        $database = $app->make(DatabaseInterface::class);
        $pdo = $database->connection()->pdo();
        $pdo->exec('CREATE TABLE custom_relationships (principal_id TEXT NOT NULL, relation TEXT NOT NULL, resource_key TEXT NOT NULL, scope TEXT NOT NULL)');
        $pdo->exec("INSERT INTO custom_relationships (principal_id, relation, resource_key, scope) VALUES ('u_2', 'owner', 'string:doc-2', 'global')");

        $repository = new DatabaseRelationshipRepository($database, null, 'custom_relationships');

        self::assertTrue($repository->hasRelationship('u_2', 'owner', 'doc-2', Scope::GLOBAL));
        $database->connection()->disconnect();
    }

    public function test_database_repository_lists_and_revokes_relationships_by_key(): void
    {
        $app = $this->makeApplication();
        $database = $app->make(DatabaseInterface::class);
        $this->seedRelationshipTable($database, [
            ['principal_id' => 'u_3', 'relation' => 'owner', 'resource_key' => 'string:doc-3', 'scope' => 'tenant:acme'],
            ['principal_id' => 'u_3', 'relation' => 'viewer', 'resource_key' => 'string:doc-4', 'scope' => 'global'],
        ]);

        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());
        $repository = new DatabaseRelationshipRepository($database, null, null, $consistency);
        $listed = $repository->listRelationships([
            'principal_id' => 'u_3',
            'relation' => 'owner',
            'scope' => 'tenant:acme',
        ]);

        self::assertCount(1, $listed);
        self::assertSame('string:doc-3', $listed[0]['resource_key']);
        $before = $consistency->relationshipVersion('u_3', 'tenant:acme');
        self::assertTrue($repository->revokeRelationshipByKey('u_3', 'owner', 'string:doc-3', 'tenant:acme'));
        self::assertFalse($repository->hasRelationship('u_3', 'owner', 'doc-3', 'tenant:acme'));
        self::assertNotSame($before, $consistency->relationshipVersion('u_3', 'tenant:acme'));

        $database->connection()->disconnect();
    }

    private function makeApplication(): Application
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $app;
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
