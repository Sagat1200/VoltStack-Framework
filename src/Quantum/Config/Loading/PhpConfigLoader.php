<?php

declare(strict_types=1);

namespace Quantum\Config\Loading;

use Quantum\Config\ConfigDocument;
use Quantum\Config\SourceDescriptor;

final class PhpConfigLoader
{
    public function loadPath(string $configPath): LoadedConfigCollection
    {
        if (! is_dir($configPath)) {
            return new LoadedConfigCollection([], [], []);
        }

        $files = glob(rtrim($configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');

        if ($files === false) {
            return new LoadedConfigCollection([], [], []);
        }

        sort($files);

        $items = [];
        $documents = [];
        $provenance = [];
        $ordinal = 0;

        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $config = require $file;

            if (! is_string($key) || $key === '' || ! is_array($config)) {
                continue;
            }

            $items[$key] = $config;
            $provenance[$key] = $file;
            $documents[] = new ConfigDocument(
                descriptor: new SourceDescriptor(
                    id: 'php:' . $key,
                    namespace: $key,
                    layer: 'application',
                    ordinal: $ordinal++,
                    location: $file,
                    revision: $this->fileRevision($file),
                ),
                payload: $config,
            );
        }

        return new LoadedConfigCollection($items, $documents, $provenance);
    }

    private function fileRevision(string $file): ?string
    {
        $hash = @hash_file('sha256', $file);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
