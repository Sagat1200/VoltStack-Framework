<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types;

use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;
use RuntimeException;

final class TypeRegistry
{
    /**
     * @var array<string, TypeHandlerInterface>
     */
    private array $handlers = [];

    public function __construct()
    {
        $this->register(new ScalarTypeHandler('int'));
        $this->register(new ScalarTypeHandler('float'));
        $this->register(new ScalarTypeHandler('bool'));
        $this->register(new ScalarTypeHandler('string'));
        $this->register(new DateTimeImmutableTypeHandler());
        $this->register(new JsonTypeHandler());
        $this->register(new BackedEnumTypeHandler());
    }

    public function register(TypeHandlerInterface $handler): void
    {
        $this->handlers[$handler->id()] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$this->normalize($type)]);
    }

    public function for(string $type): TypeHandlerInterface
    {
        $normalized = $this->normalize($type);

        if (! isset($this->handlers[$normalized])) {
            throw new RuntimeException(sprintf(
                'ORM field type [%s] is not registered.',
                $type,
            ));
        }

        return $this->handlers[$normalized];
    }

    private function normalize(string $type): string
    {
        return match (strtolower(trim($type))) {
            'integer' => 'int',
            'boolean' => 'bool',
            'datetime', 'datetimeimmutable', 'date_time_immutable' => 'datetime_immutable',
            default => strtolower(trim($type)),
        };
    }
}
