<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use Quantum\Exceptions\Context\ExceptionContext;

final class ExceptionScope
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $id,
        private readonly ?string $runtimeRequestId,
        private readonly float $startedAt,
        private readonly ExceptionRuntimeLimits $limits,
        private readonly OccurrenceRegistry $registry,
        private array $metadata = [],
        private int $handlingDepth = 0,
        private ?float $finalizedAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function runtimeRequestId(): ?string
    {
        return $this->runtimeRequestId;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function limits(): ExceptionRuntimeLimits
    {
        return $this->limits;
    }

    public function registry(): OccurrenceRegistry
    {
        return $this->registry;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertActive();
        $this->metadata[$key] = $value;
    }

    public function handlingDepth(): int
    {
        return $this->handlingDepth;
    }

    public function enterHandling(): int
    {
        $this->assertActive();

        $nextDepth = $this->handlingDepth + 1;

        if ($nextDepth > $this->limits->maxHandlingDepth) {
            throw new \LogicException(sprintf(
                'ExceptionScope handling depth [%d] exceeds the configured limit [%d].',
                $nextDepth,
                $this->limits->maxHandlingDepth,
            ));
        }

        $this->handlingDepth = $nextDepth;

        return $this->handlingDepth;
    }

    public function leaveHandling(): int
    {
        if ($this->handlingDepth === 0) {
            return 0;
        }

        $this->handlingDepth--;

        return $this->handlingDepth;
    }

    /**
     * @param array<string, scalar|array|null> $attributes
     */
    public function createContext(
        ?string $occurrenceId = null,
        ?string $correlationId = null,
        ?string $locale = null,
        bool $debug = false,
        array $attributes = [],
    ): ExceptionContext {
        $this->assertActive();

        return new ExceptionContext(
            scopeId: $this->id,
            occurrenceId: $occurrenceId,
            correlationId: $correlationId,
            locale: $locale,
            debug: $debug,
            attributes: $attributes,
            scope: $this,
        );
    }

    public function finalize(): void
    {
        if ($this->finalizedAt !== null) {
            return;
        }

        $this->registry->close();
        $this->handlingDepth = 0;
        $this->finalizedAt = microtime(true);
    }

    public function isFinalized(): bool
    {
        return $this->finalizedAt !== null;
    }

    public function finalizedAt(): ?float
    {
        return $this->finalizedAt;
    }

    private function assertActive(): void
    {
        if ($this->isFinalized()) {
            throw new \LogicException(sprintf('ExceptionScope [%s] is finalized.', $this->id));
        }
    }
}
