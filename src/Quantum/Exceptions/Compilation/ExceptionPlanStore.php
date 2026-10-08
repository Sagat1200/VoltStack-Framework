<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Compilation;

final class ExceptionPlanStore
{
    private readonly string $storagePath;

    public function __construct(string $storagePath)
    {
        $path = rtrim($storagePath, '\\/');

        if ($path === '') {
            throw new ExceptionCompilationException('Exception plan storage path cannot be empty.');
        }

        $this->storagePath = $path;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function currentPath(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . 'exceptions.plan.php';
    }

    public function persist(ExceptionCompilationPlan $plan): string
    {
        $this->ensureDirectory($this->storagePath);

        $path = $this->currentPath();
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
        $contents = "<?php\n\nreturn " . var_export($plan->toArray(), true) . ";\n";

        $written = @file_put_contents($tempPath, $contents, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            throw new ExceptionCompilationException(sprintf('Failed to write exception plan to temp path [%s].', $tempPath));
        }

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);

            throw new ExceptionCompilationException(sprintf('Failed to atomically publish exception plan at [%s].', $path));
        }

        @chmod($path, 0666 & ~umask());

        return $path;
    }

    public function load(): ?ExceptionCompilationPlan
    {
        $path = $this->currentPath();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $payload = $this->includeSafe($path);

        if (! is_array($payload)) {
            throw new ExceptionCompilationException(sprintf('Exception plan artifact [%s] is invalid or corrupted.', $path));
        }

        return ExceptionCompilationPlan::fromArray($payload);
    }

    public function clear(): void
    {
        $path = $this->currentPath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function includeSafe(string $path): ?array
    {
        $level = error_reporting(0);

        try {
            /** @psalm-suppress UnresolvableInclude */
            $result = include $path;
        } finally {
            error_reporting($level);
        }

        return is_array($result) ? $result : null;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new ExceptionCompilationException(sprintf('Unable to create exception plan storage directory [%s].', $path));
        }
    }
}
