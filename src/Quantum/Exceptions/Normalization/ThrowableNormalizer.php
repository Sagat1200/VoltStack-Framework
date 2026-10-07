<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Normalization;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;
use Quantum\Exceptions\Model\FailureSnapshot;
use Throwable;

final readonly class ThrowableNormalizer implements ExceptionNormalizerInterface
{
    /**
     * @param list<string> $projectRoots
     */
    public function __construct(
        private int $maxCauses = 8,
        private int $maxFramesPerCause = 32,
        private int $messageBytes = 2048,
        private int $snapshotBytes = 32768,
        private array $projectRoots = [],
    ) {
        if ($maxCauses <= 0) {
            throw new \InvalidArgumentException('ThrowableNormalizer maxCauses must be greater than zero.');
        }

        if ($maxFramesPerCause <= 0) {
            throw new \InvalidArgumentException('ThrowableNormalizer maxFramesPerCause must be greater than zero.');
        }

        if ($messageBytes <= 0 || $snapshotBytes <= 0) {
            throw new \InvalidArgumentException('ThrowableNormalizer byte limits must be greater than zero.');
        }
    }

    public function normalize(Throwable $error, ExceptionContext $context): FailureSnapshot
    {
        $visited = new \SplObjectStorage();
        $causes = [];
        $frames = [];
        $bytesUsed = 0;
        $truncated = false;
        $current = $error;
        $depth = 0;

        while ($current !== null && $depth < $this->maxCauses) {
            if ($visited->contains($current)) {
                $truncated = true;
                break;
            }

            $visited->attach($current);

            $safeMessage = $this->normalizeMessage($current->getMessage(), $this->messageBytes, $truncated);
            $bytesUsed += strlen($safeMessage);

            if ($depth === 0) {
                $frames = $this->normalizeFrames($current->getTrace(), $truncated, $bytesUsed);
            } else {
                $causes[] = $current::class . ': ' . $safeMessage;
                $bytesUsed += strlen($causes[array_key_last($causes)]);
            }

            if ($bytesUsed >= $this->snapshotBytes) {
                $truncated = true;
                break;
            }

            $next = $current->getPrevious();
            if (! $next instanceof Throwable) {
                break;
            }

            $current = $next;
            $depth++;
        }

        if ($current?->getPrevious() instanceof Throwable) {
            $truncated = true;
        }

        return new FailureSnapshot(
            className: $error::class,
            internalMessage: $this->normalizeMessage($error->getMessage(), $this->messageBytes, $truncated),
            phpCode: $error->getCode(),
            frames: $frames,
            causes: $causes,
            origin: $this->normalizeOrigin($context),
            safeMetadata: $this->normalizeMetadata($context->attributes),
            truncated: $truncated,
        );
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @return list<array<string, scalar|null>>
     */
    private function normalizeFrames(array $trace, bool &$truncated, int &$bytesUsed): array
    {
        $frames = [];

        foreach ($trace as $index => $frame) {
            if (count($frames) >= $this->maxFramesPerCause || $bytesUsed >= $this->snapshotBytes) {
                $truncated = true;
                break;
            }

            $normalized = [
                'index' => $index,
                'file' => $this->normalizePath(isset($frame['file']) ? (string) $frame['file'] : null),
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'class' => isset($frame['class']) ? (string) $frame['class'] : null,
                'function' => isset($frame['function']) ? (string) $frame['function'] : null,
                'type' => isset($frame['type']) ? (string) $frame['type'] : null,
            ];

            $bytesUsed += strlen(json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            $frames[] = $normalized;
        }

        return $frames;
    }

    private function normalizeMessage(string $message, int $maxBytes, bool &$truncated): string
    {
        $value = $this->sanitizeUtf8($message);
        $value = $this->redactSecrets($value);

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $truncated = true;

        return substr($value, 0, max(0, $maxBytes - 12)) . '[TRUNCATED]';
    }

    /**
     * @param array<string, scalar|array|null> $attributes
     * @return array<string, scalar|array|null>
     */
    private function normalizeMetadata(array $attributes): array
    {
        $normalized = [];
        $bytesUsed = 0;

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (! $this->isSupportedMetadataValue($value)) {
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $bytes = strlen($encoded ?: '');

            if ($bytesUsed + $bytes > $this->snapshotBytes) {
                break;
            }

            $normalized[$key] = $value;
            $bytesUsed += $bytes;
        }

        return $normalized;
    }

    private function normalizeOrigin(ExceptionContext $context): string
    {
        $origin = $context->attributes['origin'] ?? 'unknown';

        return is_string($origin) && trim($origin) !== '' ? trim($origin) : 'unknown';
    }

    private function normalizePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', trim($path));

        foreach ($this->projectRoots as $root) {
            $candidate = str_replace('\\', '/', rtrim($root, '/\\'));

            if ($candidate !== '' && str_starts_with(strtolower($normalized), strtolower($candidate . '/'))) {
                return ltrim(substr($normalized, strlen($candidate)), '/');
            }
        }

        $driveNormalized = preg_replace('#^[A-Za-z]:/#', '', $normalized);

        return $driveNormalized === null ? $normalized : $driveNormalized;
    }

    private function sanitizeUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = is_string($converted) ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '?', $value) ?? '';
    }

    private function redactSecrets(string $value): string
    {
        $patterns = [
            '/(password\s*[:=]\s*)([^\s,;]+)/iu',
            '/(token\s*[:=]\s*)([^\s,;]+)/iu',
            '/(authorization\s*[:=]\s*)([^\s,;]+)/iu',
            '/(secret\s*[:=]\s*)([^\s,;]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]', $value) ?? $value;
        }

        return $value;
    }

    private function isSupportedMetadataValue(mixed $value): bool
    {
        if (is_null($value) || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isSupportedMetadataValue($item)) {
                return false;
            }
        }

        return true;
    }
}
