<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Context;

use Quantum\Http\Request;

final class RuntimeContext
{
    /**
     * @var array<string, self>
     */
    private static array $activeContexts = [];

    /**
     * @var list<string>
     */
    private static array $activeContextStack = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $requestId,
        private readonly Request $request,
        private readonly float $startedAt,
        private array $metadata = [],
    ) {
    }

    public static function current(): ?self
    {
        if (self::$activeContextStack === []) {
            return null;
        }

        $slotId = self::$activeContextStack[array_key_last(self::$activeContextStack)];

        return self::$activeContexts[$slotId] ?? null;
    }

    public static function has(): bool
    {
        return self::current() !== null;
    }

    /**
     * @internal Se mantiene para reseteo de estado en pruebas y puentes de
     * compatibilidad. En ejecucion productiva el contexto activo debe provenir
     * de {@see self::activate()} y del stack de slots.
     */
    public static function setCurrent(?self $context): void
    {
        self::$activeContexts = [];
        self::$activeContextStack = [];

        if ($context !== null) {
            self::$activeContexts['legacy'] = $context;
            self::$activeContextStack[] = 'legacy';
        }
    }

    public static function activate(self $context): string
    {
        $slotId = bin2hex(random_bytes(12));

        self::$activeContexts[$slotId] = $context;
        self::$activeContextStack[] = $slotId;

        return $slotId;
    }

    public static function deactivate(?string $slotId): void
    {
        if ($slotId === null) {
            return;
        }

        unset(self::$activeContexts[$slotId]);
        self::$activeContextStack = array_values(array_filter(
            self::$activeContextStack,
            static fn (string $activeSlotId): bool => $activeSlotId !== $slotId,
        ));
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
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
        $this->metadata[$key] = $value;
    }
}
