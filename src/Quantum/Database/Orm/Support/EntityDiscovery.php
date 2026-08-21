<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Support;

use Quantum\Database\Orm\Metadata\Attribute\Entity as EntityAttribute;

final class EntityDiscovery
{
    /**
     * @param list<class-string>|array<int,string> $entities
     * @param list<string> $paths
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $entities = [],
        private readonly array $paths = [],
    ) {}

    /**
     * @return list<class-string>
     */
    public function discover(): array
    {
        $classes = [];

        foreach ($this->entities as $entity) {
            if (!is_string($entity) || trim($entity) === '') {
                continue;
            }

            $classes[] = trim($entity);
        }

        $paths = $this->normalizedPaths();
        if ($paths === []) {
            return array_values(array_unique($classes));
        }

        $loadedClasses = $this->loadClassesFromPaths($paths);

        foreach ($loadedClasses as $class) {
            try {
                $reflection = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $fileName = $reflection->getFileName();
            if ($fileName === false || !$this->startsWithAnyPath($fileName, $paths)) {
                continue;
            }

            if ($reflection->getAttributes(EntityAttribute::class) === []) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        /** @var list<class-string> $classes */
        return $classes;
    }

    /**
     * @return list<string>
     */
    private function normalizedPaths(): array
    {
        $paths = [];

        foreach ($this->paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $resolved = $this->normalizePath($path);
            if (!is_dir($resolved)) {
                continue;
            }

            $paths[] = rtrim($resolved, '\\/');
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     * @return list<class-string>
     */
    private function loadClassesFromPaths(array $paths): array
    {
        $before = get_declared_classes();

        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                require_once $file->getPathname();
            }
        }

        $declared = get_declared_classes();
        $newClasses = array_values(array_diff($declared, $before));

        foreach ($before as $class) {
            $newClasses[] = $class;
        }

        /** @var list<class-string> $newClasses */
        return array_values(array_unique($newClasses));
    }

    /**
     * @param list<string> $paths
     */
    private function startsWithAnyPath(string $path, array $paths): bool
    {
        foreach ($paths as $basePath) {
            if (str_starts_with(strtolower($path), strtolower($basePath))) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->basePath, '\\/') . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}
