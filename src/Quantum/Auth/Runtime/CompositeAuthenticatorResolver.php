<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;

final class CompositeAuthenticatorResolver implements AuthenticatorResolverInterface
{
    /**
     * @var array<int, array{0: int, 1: AuthenticatorResolverInterface}>
     */
    private array $resolvers = [];

    /**
     * @var bool
     */
    private bool $sorted = false;

    public function addResolver(AuthenticatorResolverInterface $resolver, int $priority = 0): void
    {
        $this->resolvers[] = [$priority, $resolver];
        $this->sorted = false;
    }

    /**
     * @return array<int, AuthenticatorResolverInterface>
     */
    public function resolvers(): array
    {
        if (! $this->sorted) {
            usort(
                $this->resolvers,
                /**
                 * @param array{0: int, 1: AuthenticatorResolverInterface} $a
                 * @param array{0: int, 1: AuthenticatorResolverInterface} $b
                 */
                static fn (array $a, array $b): int => $b[0] <=> $a[0],
            );
            $this->sorted = true;
        }

        $result = [];
        foreach ($this->resolvers as $entry) {
            $result[] = $entry[1];
        }

        return $result;
    }

    public function resolve(AuthenticationOperationContext $context): array
    {
        $collected = [];
        $seen = [];

        foreach ($this->resolvers() as $resolver) {
            foreach ($resolver->resolve($context) as $candidate) {
                if (! $candidate instanceof AuthenticatorInterface) {
                    continue;
                }
                $key = spl_object_hash($candidate);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $collected[] = $candidate;
            }
        }

        return $collected;
    }
}
