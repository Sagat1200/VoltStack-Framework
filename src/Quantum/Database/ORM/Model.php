<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use RuntimeException;
use VoltStack\Framework\Application;

abstract class Model
{
    protected static string $table = '';

    public static function query(): EntityQuery
    {
        return static::entityManager()->query(static::class);
    }

    public static function find(mixed $identifier): ?static
    {
        $entity = static::entityManager()->find(static::class, $identifier);

        return $entity instanceof static ? $entity : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        $metadata = static::metadataRegistry()->for(static::class);

        foreach ($attributes as $field => $value) {
            $metadata->field($field)->setValue($this, $value);
        }

        return $this;
    }

    public function save(): bool
    {
        $manager = static::entityManager();
        $manager->persist($this);
        $manager->flush();

        return true;
    }

    public function delete(): bool
    {
        $manager = static::entityManager();
        $manager->remove($this);
        $manager->flush();

        return true;
    }

    public function refresh(): static
    {
        static::entityManager()->refresh($this);

        return $this;
    }

    protected static function entityManager(): EntityManagerInterface
    {
        $app = Application::getInstance();

        if ($app === null) {
            throw new RuntimeException('The VoltStack application instance has not been bootstrapped.');
        }

        return $app->make(EntityManagerInterface::class);
    }

    protected static function metadataRegistry(): EntityMetadataRegistry
    {
        $app = Application::getInstance();

        if ($app === null) {
            throw new RuntimeException('The VoltStack application instance has not been bootstrapped.');
        }

        return $app->make(EntityMetadataRegistry::class);
    }
}
