<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\Factories\FactoryRegistry;
use Quantum\Database\ORM\Attributes\Column;
use Quantum\Database\ORM\Attributes\Entity;
use Quantum\Database\ORM\Attributes\Id;
use Quantum\Database\ORM\Attributes\Table;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\Schema\Builder\TableBlueprint;
use Quantum\Database\Seeders\SeederRunner;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class DatabaseFactoriesSeedersFeatureTest extends TestCase
{
    private string $basePath;
    private string $databasePath;
    private ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-database-factseed-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'factories', 0777, true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders', 0777, true);
        $this->databasePath = $this->basePath . DIRECTORY_SEPARATOR . 'database.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->app->make(ConnectionManagerInterface::class)->disconnectAll();
            $this->app = null;
        }

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_factory_registry_make_and_create_then_seeder_runner_populates_via_discovery_over_sqlite(): void
    {
        $app = $this->makeApp();
        $scope = $app->make(ScopeManager::class);
        $scope->begin(Request::create('/database/factseed/run', 'POST'));

        try {
            $database = $app->make(DatabaseInterface::class);
            $database->schema()->create('dbs_articles', function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('slug');
                $table->integer('views')->default(0);
                $table->boolean('published');
            }, true);

            $metadata = $app->make(EntityMetadataRegistry::class);
            self::assertTrue($metadata->has(DbsArticle::class));
            $articleMeta = $metadata->for(DbsArticle::class);
            self::assertSame('dbs_articles', $articleMeta->table);

            $connManager = $app->make(\Quantum\Database\Contracts\ConnectionManagerInterface::class);
            $defaultConn = $connManager->connection('default');
            $realDbPath = $defaultConn->definition()->database;
            self::assertTrue(file_exists($this->databasePath) || file_exists($realDbPath), 'SQLite DB file must exist after schema create.');
            self::assertSame(
                str_replace('\\', '/', realpath($this->databasePath) ?: $this->databasePath),
                str_replace('\\', '/', realpath($realDbPath) ?: $realDbPath),
                "Configured databasePath must match connection's real file. Config={$this->databasePath} | Actual={$realDbPath}"
            );
            self::assertGreaterThan(0, filesize($this->databasePath), 'SQLite file must be non-empty after schema create.');

            $probe = new DbsArticle();
            $probe->title = 'ProbeTitle';
            $probe->slug = 'probe-slug';
            $probe->views = 7;
            $probe->published = true;
            $probeWrite = $articleMeta->extractForWrite($probe, false);
            self::assertIsArray($probeWrite);
            self::assertSame(
                ['title', 'slug', 'views', 'published'],
                array_keys($probeWrite),
                'EntityMetadata extractForWrite(entity, includeIdentifier=false) must return exactly 4 DB columns.'
            );
            self::assertSame('ProbeTitle', $probeWrite['title']);
            self::assertSame('probe-slug', $probeWrite['slug']);
            self::assertSame(7, $probeWrite['views']);
            self::assertSame(true, $probeWrite['published']);
            self::assertSame('id', $articleMeta->identifier->column, 'Identifier column is id.');
            self::assertSame('title', $articleMeta->field('title')->column, 'Title field.column == title.');

            $this->writeArticleFactory();
            $this->writeDatabaseSeeder();

            $registry = $app->make(FactoryRegistry::class);
            $factory = $registry->for(DbsArticle::class);
            self::assertSame(DbsArticle::class, $factory->entityClass());

            $ghost = $factory->make(['title' => 'Ghost', 'slug' => 'ghost', 'views' => 99, 'published' => false]);
            self::assertInstanceOf(DbsArticle::class, $ghost);
            self::assertSame('Ghost', $ghost->title);
            self::assertSame('ghost', $ghost->slug);
            self::assertSame(99, $ghost->views);
            self::assertFalse($ghost->published);
            self::assertNull($ghost->id, 'make() must NOT assign an id / persist.');

            $rowsBefore = $this->countArticlesRaw();
            self::assertSame(0, $rowsBefore, 'No rows must exist before running factories/create or seeder.');

            $manager = $database->entityManager();
            for ($i = 0; $i < 3; $i++) {
                $a = new DbsArticle();
                $a->title = 'Manual' . $i;
                $a->slug = 'm' . $i;
                $a->views = 5;
                $a->published = true;
                $manager->persist($a);
            }
            $manager->flush();
            $manualCount = $this->countArticlesRaw();
            self::assertSame(3, $manualCount, "Manual persist 3 articles via manager, got $manualCount instead.");

            $countFive = $factory->times(5)->create(['published' => true]);
            self::assertIsArray($countFive);
            self::assertCount(5, $countFive);
            $database->entityManager()->flush();
            $afterFactory = $this->countArticlesRaw();
            self::assertSame(8, $afterFactory, "3 manual + factory->times(5)->create() = 8 rows, got $afterFactory.");

            $database->entityManager()->clear();

            $seederName = $app->make(SeederRunner::class)->run()::class;
            self::assertNotEmpty($seederName);

            $total = $this->countArticlesRaw();
            self::assertSame(16, $total, '3 manual + 5 factory + 5 + 3 seeder = 16 rows. Actual: ' . $total);

            $unpublishedZeroViews = $database
                ->entityManager()
                ->query(DbsArticle::class)
                ->where('published', false)
                ->where('views', 0)
                ->get();
            self::assertCount(3, $unpublishedZeroViews, 'Seeder explicit ->create(published:false, views:0) for 3 items.');

            $published = $database
                ->entityManager()
                ->query(DbsArticle::class)
                ->where('published', true)
                ->orderBy('id', 'asc')
                ->get();
            self::assertCount(13, $published, '3 manual + 5 factory + 5 seeder published = 13.');

            $repo = $database->repository(DbsArticle::class);
            self::assertCount(16, $repo->findAll());
        } finally {
            $scope->end();
        }
    }

    private function makeApp(): Application
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'default');
        $config->set('database.connections.default', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
        ]);

        return $this->app = $app;
    }

    private function countArticlesRaw(): int
    {
        /** @var DatabaseInterface $db */
        $db = $this->app->make(DatabaseInterface::class);
        $rows = $db->table('dbs_articles')->get();

        return is_countable($rows) ? count($rows) : 0;
    }

    private function writeArticleFactory(): void
    {
        $path = $this->basePath
            . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'factories'
            . DIRECTORY_SEPARATOR . 'ArticleFactory.php';

        file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use VoltStack\Test\Feature\DbsArticle;
use Quantum\Database\Factories\AbstractFactory;
use VoltStack\Framework\Application;

return static function (Application $app): AbstractFactory {
    return new class($app) extends AbstractFactory {
        public function definition(): array
        {
            static $counter = 0;
            $counter++;

            return [
                'title'     => 'Factory Article #' . $counter,
                'slug'      => 'factory-article-' . $counter,
                'views'     => random_int(0, 500),
                'published' => true,
            ];
        }

        public function entityClass(): string
        {
            return DbsArticle::class;
        }
    };
};
PHP
        );

        self::assertFileExists($path);
    }

    private function writeDatabaseSeeder(): void
    {
        $path = $this->basePath
            . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'seeders'
            . DIRECTORY_SEPARATOR . 'DatabaseSeeder.php';

        file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use VoltStack\Test\Feature\DbsArticle;
use Quantum\Database\Seeders\AbstractSeeder;
use VoltStack\Framework\Application;

return static function (Application $app): AbstractSeeder {
    return new class extends AbstractSeeder {
        public function run(Application $application): void
        {
            $this->factory(DbsArticle::class, 5)->create(['published' => true]);
            $this->factory(DbsArticle::class, 3)->create(['published' => false, 'views' => 0]);
        }
    };
};
PHP
        );

        self::assertFileExists($path);
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}

#[Entity]
#[Table('dbs_articles')]
final class DbsArticle
{
    #[Id]
    #[Column(type: 'int')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public string $title = '';

    #[Column(type: 'string')]
    public string $slug = '';

    #[Column(type: 'int')]
    public int $views = 0;

    #[Column(type: 'bool')]
    public bool $published = false;
}
