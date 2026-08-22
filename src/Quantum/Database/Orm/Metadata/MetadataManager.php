<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * Default MetadataManager. Implementa interface + 3 cache layers.
 *
 * Cache stack (Algoritmo 4.2):
 *   L1: static in-process array<class-string, CompiledEntityMetadata>
 *   L2: APCu  (si function_exists('apcu_fetch'))
 *   L3: filesystem (si $cacheDir se configuró)
 *
 * En desarrollo ($devMode = true), L3 se invalida contra filemtime del PHP de la entidad.
 * En producción, L3 se usa tal cual; la invalidación es via CLI warmup.
 */
final class MetadataManager implements MetadataManagerInterface
{
    /**
     * L1 in-process cache.
     *
     * @var array<class-string,CompiledEntityMetadata>
     */
    private static array $cacheL1 = [];

    /** @var array<class-string,true> */
    private static array $knownEntities = [];

    /** @var string cache key prefix para L2/L3 (versión metadata schema) */
    private const CACHE_VERSION = 'v1';

    public function __construct(
        private readonly AttributeMetadataLoader $loader = new AttributeMetadataLoader(),
        private readonly ?string $cacheDir = null,
        private readonly bool $devMode = true,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return CompiledEntityMetadata<T>
     */
    public function getMetadataFor(string $entityClass): CompiledEntityMetadata
    {
        self::$knownEntities[$entityClass] = true;

        // L1
        if (isset(self::$cacheL1[$entityClass])) {
            /** @var CompiledEntityMetadata<T> */
            return self::$cacheL1[$entityClass];
        }

        $cacheKey = self::cacheKeyFor($entityClass);

        // L2 APCu
        $fromApcu = null;
        if (function_exists('apcu_fetch')) {
            $fetched = apcu_fetch($cacheKey, $success);
            if ($success && $fetched instanceof CompiledEntityMetadata) {
                $fromApcu = $fetched;
            }
        }
        if ($fromApcu !== null) {
            self::$cacheL1[$entityClass] = $fromApcu;
            /** @var CompiledEntityMetadata<T> */
            return $fromApcu;
        }

        // L3 filesystem
        if ($this->cacheDir !== null) {
            $file = $this->cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.php';
            if (file_exists($file)) {
                /** @noinspection PhpIncludeInspection */
                $data = require $file;
                if ($data instanceof CompiledEntityMetadata) {
                    $valid = true;
                    if ($this->devMode) {
                        // Dev: comprobar mtime del archivo de la entidad
                        try {
                            $rc = new \ReflectionClass($entityClass);
                            $fn = $rc->getFileName();
                            if ($fn !== false && @filemtime($fn) > $data->compiledAt) {
                                $valid = false;
                            }
                        } catch (\Throwable) {
                            $valid = false;
                        }
                    }
                    if ($valid) {
                        self::$cacheL1[$entityClass] = $data;
                        if (function_exists('apcu_store')) {
                            @apcu_store($cacheKey, $data);
                        }
                        /** @var CompiledEntityMetadata<T> */
                        return $data;
                    }
                }
            }
        }

        // Fresh compile
        $compiled = $this->loader->load($entityClass);

        // Almacenar en L1, L2, L3
        self::$cacheL1[$entityClass] = $compiled;
        if (function_exists('apcu_store')) {
            @apcu_store($cacheKey, $compiled);
        }
        if ($this->cacheDir !== null) {
            $this->writeCacheFile($cacheKey, $compiled);
        }

        /** @var CompiledEntityMetadata<T> */
        return $compiled;
    }

    public function getAllEntityClasses(): array
    {
        return array_keys(self::$knownEntities);
    }

    /**
     * @param iterable<class-string> $entityClasses
     */
    public function warmup(iterable $entityClasses): int
    {
        $n = 0;
        foreach ($entityClasses as $c) {
            $this->getMetadataFor($c);
            $n++;
        }
        return $n;
    }

    public function clearCache(): void
    {
        self::$cacheL1 = [];
        if (function_exists('apcu_clear_cache') && self::isCli()) {
            @apcu_clear_cache('user');
        }
        if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . 'quantum.db.meta.' . self::CACHE_VERSION . '.*.php') as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * @return array<class-string,CompiledEntityMetadata>
     */
    public function getAllMetadata(): array
    {
        $out = [];
        foreach (array_keys(self::$knownEntities) as $cls) {
            try {
                $out[$cls] = $this->getMetadataFor($cls);
            } catch (\Throwable) {
                // skip entities que no pueden cargar
            }
        }
        return $out;
    }

    // ============================ INTERNAL =====================================

    /**
     * @param class-string $entityClass
     */
    private static function cacheKeyFor(string $entityClass): string
    {
        $fingerprint = substr(bin2hex(hash('sha256', $entityClass, true)), 0, 16);

        return 'quantum.db.meta.' . self::CACHE_VERSION . '.'
            . strtr(strtolower(str_replace('\\', '_', $entityClass)), ['/' => '_', '.' => '_'])
            . '.' . $fingerprint;
    }

    private function writeCacheFile(string $cacheKey, CompiledEntityMetadata $data): void
    {
        if ($this->cacheDir === null) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o777, true);
        }
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.php';
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        $contents = "<?php\n// Quantum/Database ORM metadata cache. DO NOT EDIT.\n// Generated at " . date('c') . "\nreturn \\unserialize(" . var_export(serialize($data), true) . ");\n";
        try {
            file_put_contents($tmpFile, $contents, LOCK_EX);
            @chmod($tmpFile, 0o666 & ~umask());
            @rename($tmpFile, $file);
        } catch (\Throwable) {
            @unlink($tmpFile);
        }
    }

    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
