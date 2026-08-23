<?php

declare(strict_types=1);

namespace Quantum\Console\Commands\Database;

use Quantum\Console\Command;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Container\Exceptions\BindingResolutionException;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;
use Quantum\Database\Schema\OrmSchemaProjector;
use Quantum\Database\Schema\SchemaComparator;
use Quantum\Database\Schema\SchemaManager;
use VoltStack\Framework\Application;

final class DbMakeMigrationCommand extends Command
{
    public function name(): string
    {
        return 'db:make-migration';
    }

    public function description(): string
    {
        return 'Genera un archivo de migración base y puede adjuntar el plan actual de schema diff.';
    }

    public function usage(): string
    {
        return 'db:make-migration <name> [--diff]';
    }

    public function category(): string
    {
        return 'Database';
    }

    public function argumentsHelp(): array
    {
        return [
            'name' => 'Nombre lógico de la migración, por ejemplo add_user_status.',
        ];
    }

    public function optionsHelp(): array
    {
        return [
            '--diff' => 'Incluye como comentarios el plan actual de schema diff.',
        ];
    }

    public function handle(Input $input, Output $output): int
    {
        $name = trim($input->arguments()[0] ?? '');
        if ($name === '') {
            $output->error('Debes indicar un nombre de migración.');
            return 1;
        }

        try {
            $app = $this->bootstrapApplication();
            $version = gmdate('YmdHis');
            $slug = $this->slug($name);
            $class = $this->className($name);
            $directory = $this->resolveMigrationsDirectory($app);
            $path = $directory . DIRECTORY_SEPARATOR . $version . '_' . $slug . '.php';

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            if (file_exists($path)) {
                throw new \RuntimeException(sprintf('Migration file [%s] already exists.', $path));
            }

            $comments = $input->hasOption('diff')
                ? $this->buildDiffComments($app)
                : ['// Fill in SQL or ORM-aware operations here.'];

            $contents = $this->renderMigration($class, $version, $name, $comments);
            file_put_contents($path, $contents);

            $output->writeln(sprintf('Migración creada: %s', $path));
            return 0;
        } catch (\Throwable $e) {
            $output->error(sprintf('db:make-migration failed: %s', $e->getMessage()));
            return 1;
        }
    }

    private function resolveMigrationsDirectory(Application $app): string
    {
        $paths = $app->config('database.migrations.paths', ['database/migrations']);
        $first = is_array($paths) && $paths !== [] ? (string) $paths[0] : 'database/migrations';

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $first) === 1 || str_starts_with($first, DIRECTORY_SEPARATOR)) {
            return $first;
        }

        return $app->basePath($first);
    }

    /**
     * @return list<string>
     */
    private function buildDiffComments(Application $app): array
    {
        try {
            /** @var SchemaManager $schema */
            $schema = $app->make(SchemaManager::class);
            /** @var MetadataManagerInterface $metadata */
            $metadata = $app->make(MetadataManagerInterface::class);
            /** @var EntityDiscovery $discovery */
            $discovery = $app->make(EntityDiscovery::class);
        } catch (BindingResolutionException $e) {
            return ['// Diff no disponible: falta OrmServiceProvider o metadata ORM configurada.'];
        }

        $report = (new SchemaComparator())->compare(
            actual: $schema->snapshot(),
            desired: (new OrmSchemaProjector(
                metadata: $metadata,
                discovery: $discovery,
                driverName: $schema->driverName(),
            ))->project(),
        );

        if ($report->isEmpty()) {
            return ['// No differences detected between live schema and ORM metadata.'];
        }

        $lines = ['// Suggested plan from current schema diff:'];

        foreach ($report->actions as $action) {
            $lines[] = '// - ' . $action->message;
            if ($action->sql !== null) {
                $lines[] = '//   SQL: ' . $action->sql . ';';
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $comments
     */
    private function renderMigration(string $class, string $version, string $name, array $comments): string
    {
        $commentBlock = implode("\n        ", $comments);

        return <<<PHP
<?php

declare(strict_types=1);

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Migration\AbstractMigration;

final class {$class} extends AbstractMigration
{
    public function version(): string
    {
        return '{$version}';
    }

    public function description(): string
    {
        return '{$this->escapeString($name)}';
    }

    public function up(ConnectionInterface \$connection): void
    {
        {$commentBlock}
    }

    public function down(ConnectionInterface \$connection): void
    {
        // Write the rollback counterpart here.
    }
}
PHP;
    }

    private function slug(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? 'migration';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : 'migration';
    }

    private function className(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        $studly = implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));

        return ($studly !== '' ? $studly : 'GeneratedMigration') . 'Migration';
    }

    private function escapeString(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
