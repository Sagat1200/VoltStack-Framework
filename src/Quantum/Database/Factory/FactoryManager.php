<?php

declare(strict_types=1);

namespace Quantum\Database\Factory;

use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Container\Exceptions\BindingResolutionException;
use VoltStack\Framework\Application;

final class FactoryManager
{
    public function __construct(
        private readonly Application $app,
        private readonly FactoryDiscovery $discovery,
        private readonly int $defaultSeed = 12345,
    ) {}

    public function app(): Application
    {
        return $this->app;
    }

    public function defaultSeed(): int
    {
        return $this->defaultSeed;
    }

    /**
     * @return list<array{name:string,class:string,entity:string,description:string}>
     */
    public function status(): array
    {
        $rows = [];

        foreach ($this->discovery->discover() as $factory) {
            $rows[] = [
                'name' => $factory->name(),
                'class' => $factory::class,
                'entity' => $factory->entityClass(),
                'description' => $factory->description(),
            ];
        }

        return $rows;
    }

    public function factory(string $target): FactoryBuilder
    {
        $factory = $this->resolve($target);

        return new FactoryBuilder($this, $factory);
    }

    /**
     * @param list<object> $entities
     */
    public function persist(array $entities): void
    {
        if ($entities === []) {
            return;
        }

        try {
            /** @var EntityManagerInterface $em */
            $em = $this->app->make(EntityManagerInterface::class);
        } catch (BindingResolutionException $e) {
            throw new \RuntimeException('Factory create() requiere OrmServiceProvider y EntityManagerInterface disponible.', previous: $e);
        }

        foreach ($entities as $entity) {
            $em->persist($entity);
        }

        $em->flush();
    }

    private function resolve(string $target): FactoryInterface
    {
        $normalized = trim($target);

        foreach ($this->discovery->discover() as $factory) {
            if ($factory->name() === $normalized || $factory::class === $normalized || $factory->entityClass() === $normalized) {
                return $factory;
            }
        }

        throw new \RuntimeException(sprintf('Factory [%s] no fue encontrada.', $normalized));
    }
}