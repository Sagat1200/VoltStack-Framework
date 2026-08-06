<?php

declare(strict_types=1);

namespace Quantum\Compilation;

use Quantum\Compilation\Contracts\BuildManifestInterface;
use Quantum\Compilation\Exceptions\BuildActivationException;

final class BuildManifest implements BuildManifestInterface
{
    private string $manifestPath;

    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    private bool $dirty = false;

    public function __construct(string $storageRoot)
    {
        $root = rtrim($storageRoot, '\\/');

        if ($root === '') {
            throw new BuildActivationException('Build manifest storage root cannot be empty.');
        }

        $this->manifestPath = $root . DIRECTORY_SEPARATOR . 'builds.manifest.json';
    }

    public function __destruct()
    {
        if ($this->dirty) {
            $this->persist();
        }
    }

    public function create(): Build
    {
        $data = $this->load();
        $buildId = date('YmdHis') . '.' . bin2hex(random_bytes(3));

        $build = new Build(
            id: $buildId,
            createdAt: time(),
        );

        $data['builds'][$buildId] = $this->buildToArray($build);
        $data['sequence'][] = $buildId;

        $this->cache = $data;
        $this->dirty = true;
        $this->persist();

        return $build;
    }

    public function get(string $buildId): ?Build
    {
        $data = $this->load();

        if (! isset($data['builds'][$buildId])) {
            return null;
        }

        return $this->arrayToBuild($data['builds'][$buildId]);
    }

    public function current(): ?Build
    {
        $data = $this->load();
        $currentId = $data['current'] ?? null;

        if (! is_string($currentId) || $currentId === '') {
            return null;
        }

        if (! isset($data['builds'][$currentId])) {
            return null;
        }

        return $this->arrayToBuild($data['builds'][$currentId]);
    }

    public function setCurrent(string $buildId): Build
    {
        $data = $this->load();

        if (! isset($data['builds'][$buildId])) {
            throw new BuildActivationException(sprintf('Cannot set current build: build [%s] does not exist.', $buildId));
        }

        $previous = $data['current'] ?? '';
        $buildData = $data['builds'][$buildId];

        $buildData['active'] = true;
        $buildData['activatedAt'] = time();
        $buildData['previousBuildId'] = is_string($previous) ? $previous : '';

        if (is_string($previous) && $previous !== '' && isset($data['builds'][$previous])) {
            $data['builds'][$previous]['active'] = false;
        }

        $data['builds'][$buildId] = $buildData;
        $data['current'] = $buildId;
        $data['updatedAt'] = time();

        $this->cache = $data;
        $this->dirty = true;
        $this->persist();

        return $this->arrayToBuild($buildData);
    }

    public function all(): array
    {
        $data = $this->load();
        $sequence = $data['sequence'] ?? [];

        if (! is_array($sequence)) {
            return [];
        }

        $builds = [];

        foreach (array_reverse($sequence) as $id) {
            if (! is_string($id) || ! isset($data['builds'][$id])) {
                continue;
            }

            $builds[] = $this->arrayToBuild($data['builds'][$id]);
        }

        return $builds;
    }

    public function prune(int $retain = 3): array
    {
        $data = $this->load();
        $sequence = is_array($data['sequence'] ?? null) ? $data['sequence'] : [];
        $reversedSeq = array_reverse($sequence);

        $currentId = $data['current'] ?? null;
        $keepIds = [];
        $kept = 0;

        foreach ($reversedSeq as $id) {
            if (! is_string($id)) {
                continue;
            }

            if ($id === $currentId) {
                $keepIds[] = $id;

                continue;
            }

            if ($kept < max(0, $retain - 1)) {
                $keepIds[] = $id;
                $kept++;
            }
        }

        if (is_string($currentId) && ! in_array($currentId, $keepIds, true)) {
            array_unshift($keepIds, $currentId);
        }

        $prunedBuilds = [];
        $newBuilds = [];
        $newSequence = [];

        foreach ($sequence as $id) {
            if (! is_string($id)) {
                continue;
            }

            if (in_array($id, $keepIds, true)) {
                $newSequence[] = $id;

                if (isset($data['builds'][$id])) {
                    $newBuilds[$id] = $data['builds'][$id];
                    $prunedBuilds[] = $this->arrayToBuild($data['builds'][$id]);
                }
            }
        }

        $data['builds'] = $newBuilds;
        $data['sequence'] = $newSequence;
        $data['updatedAt'] = time();

        $this->cache = $data;
        $this->dirty = true;
        $this->persist();

        return $prunedBuilds;
    }

    public function previous(): ?Build
    {
        $current = $this->current();

        if ($current === null) {
            return null;
        }

        $prevId = $current->previousBuildId;

        if ($prevId === '') {
            $all = $this->all();
            $foundCurrent = false;

            foreach ($all as $build) {
                if ($foundCurrent) {
                    return $build;
                }

                if ($build->id === $current->id) {
                    $foundCurrent = true;
                }
            }

            return null;
        }

        return $this->get($prevId);
    }

    public function rollback(): ?Build
    {
        $previousBuild = $this->previous();

        if ($previousBuild === null) {
            return null;
        }

        return $this->setCurrent($previousBuild->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->cache !== []) {
            return $this->cache;
        }

        if (! is_file($this->manifestPath)) {
            $initial = [
                'schema' => 1,
                'createdAt' => time(),
                'updatedAt' => time(),
                'current' => null,
                'builds' => [],
                'sequence' => [],
            ];
            $this->cache = $initial;
            $this->dirty = true;

            return $initial;
        }

        $contents = @file_get_contents($this->manifestPath);

        if (! is_string($contents) || trim($contents) === '') {
            $initial = [
                'schema' => 1,
                'createdAt' => time(),
                'updatedAt' => time(),
                'current' => null,
                'builds' => [],
                'sequence' => [],
            ];
            $this->cache = $initial;

            return $initial;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $initial = [
                'schema' => 1,
                'createdAt' => time(),
                'updatedAt' => time(),
                'current' => null,
                'builds' => [],
                'sequence' => [],
            ];
            $this->cache = $initial;

            return $initial;
        }

        $this->cache = $decoded;

        return $this->cache;
    }

    private function persist(): void
    {
        $dir = dirname($this->manifestPath);

        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $encoded = json_encode($this->cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return;
        }

        $tempPath = $this->manifestPath . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tempPath, $encoded, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            return;
        }

        @rename($tempPath, $this->manifestPath);
        @chmod($this->manifestPath, 0666 & ~umask());

        $this->dirty = false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildToArray(Build $build): array
    {
        return [
            'id' => $build->id,
            'createdAt' => $build->createdAt,
            'controllerCount' => $build->controllerCount,
            'format' => $build->format,
            'active' => $build->active,
            'activatedAt' => $build->activatedAt,
            'previousBuildId' => $build->previousBuildId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToBuild(array $data): Build
    {
        return new Build(
            id: isset($data['id']) && is_string($data['id']) ? $data['id'] : uniqid('build_', true),
            createdAt: isset($data['createdAt']) ? (int) $data['createdAt'] : time(),
            controllerCount: isset($data['controllerCount']) ? (int) $data['controllerCount'] : 0,
            format: isset($data['format']) && is_string($data['format']) ? $data['format'] : 'php',
            active: isset($data['active']) ? (bool) $data['active'] : false,
            activatedAt: isset($data['activatedAt']) ? (int) $data['activatedAt'] : 0,
            previousBuildId: isset($data['previousBuildId']) && is_string($data['previousBuildId']) ? $data['previousBuildId'] : '',
        );
    }
}