<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final class MigrationDiscovery
{
    /**
     * @param list<string> $paths
     * @param list<class-string<MigrationInterface>> $classes
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $paths = [],
        private readonly array $classes = [],
    ) {}

    /**
     * @return list<MigrationInterface>
     */
    public function discover(): array
    {
        $migrations = [];

        foreach ($this->classes as $class) {
            $migrations[] = $this->makeMigration($class);
        }

        foreach ($this->discoverFiles() as $file) {
            foreach ($this->loadClassesFromFile($file) as $class) {
                if (!is_a($class, MigrationInterface::class, true)) {
                    continue;
                }

                $migrations[] = $this->makeMigration($class);
            }
        }

        $unique = [];

        foreach ($migrations as $migration) {
            $version = trim($migration->version());
            if ($version === '') {
                throw new \RuntimeException(sprintf('Migration [%s] must declare a non-empty version.', $migration::class));
            }

            if (isset($unique[$version])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate migration version [%s] found in [%s] and [%s].',
                    $version,
                    $unique[$version]::class,
                    $migration::class,
                ));
            }

            $unique[$version] = $migration;
        }

        ksort($unique, \SORT_STRING);

        return array_values($unique);
    }

    /**
     * @return list<string>
     */
    private function discoverFiles(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $resolved = $this->normalizePath($path);
            if (!is_dir($resolved)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }

                if (strtolower($item->getExtension()) !== 'php') {
                    continue;
                }

                $files[] = $item->getPathname();
            }
        }

        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @return list<class-string>
     */
    private function loadClassesFromFile(string $file): array
    {
        require_once $file;

        return $this->parseClassesFromFile($file);
    }

    /**
     * @param class-string<MigrationInterface> $class
     */
    private function makeMigration(string $class): MigrationInterface
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Configured migration class [%s] was not found.', $class));
        }

        if (!is_a($class, MigrationInterface::class, true)) {
            throw new \RuntimeException(sprintf('Configured migration [%s] must implement [%s].', $class, MigrationInterface::class));
        }

        return new $class();
    }

    /**
     * @return list<class-string>
     */
    private function parseClassesFromFile(string $file): array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $classes = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === \T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $index + 1);
                continue;
            }

            if ($token[0] !== \T_CLASS) {
                continue;
            }

            $previous = $this->previousMeaningfulToken($tokens, $index - 1);
            if (is_array($previous) && in_array($previous[0], [\T_DOUBLE_COLON, \T_NEW], true)) {
                continue;
            }

            $className = $this->readClassName($tokens, $index + 1);
            if ($className === '') {
                continue;
            }

            $classes[] = ltrim($namespace . '\\' . $className, '\\');
        }

        /** @var list<class-string> $classes */
        return $classes;
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function readNamespace(array $tokens, int $start): string
    {
        $namespace = '';
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }

                continue;
            }

            if (in_array($token[0], [\T_STRING, \T_NS_SEPARATOR, \T_NAME_QUALIFIED], true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace, '\\');
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     */
    private function readClassName(array $tokens, int $start): string
    {
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === \T_STRING) {
                return $token[1];
            }
        }

        return '';
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private function previousMeaningfulToken(array $tokens, int $start): array|string|null
    {
        for ($index = $start; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }

                return $token;
            }

            if (in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($this->basePath, '\\/') . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}