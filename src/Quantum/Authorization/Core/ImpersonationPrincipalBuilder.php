<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Contracts\PrincipalResolverInterface;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;

/**
 * Helper interno: construye el principal ImpersonatedUser combinando
 * información del originator (caller) y el target, con claims estables
 * para trazabilidad auditiva.
 *
 * @internal
 */
final class ImpersonationPrincipalBuilder
{
    public function __construct(
        private readonly ?PrincipalResolverInterface $resolver = null,
    ) {}

    /**
     * @param mixed $caller
     * @param mixed $target
     *
     * @throws \InvalidArgumentException si caller o target no se pueden
     *   reducir a un id no vacio.
     */
    public function build(
        mixed $caller,
        mixed $target,
        Scope|string|null $scope = null,
    ): PrincipalInterface {
        $callerId = $this->resolveId($caller);
        $targetId = $this->resolveId($target);

        if ($callerId === '' || $targetId === '') {
            throw new \InvalidArgumentException(
                'Impersonation caller and target principal ids must resolve to non-empty strings.',
            );
        }

        $scopeValue = $scope instanceof Scope ? $scope->value : (is_string($scope) && $scope !== '' ? $scope : null);

        $claims = array_filter([
            'originator_principal_id' => $callerId,
            'target_principal_id' => $targetId,
            'acting_as' => $targetId,
            'impersonation_scope' => $scopeValue,
            'impersonated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ], static fn (mixed $v): bool => $v !== null);

        return new Principal(
            id: $targetId,
            type: PrincipalType::ImpersonatedUser,
            authenticated: true,
            claims: $claims,
        );
    }

    private function resolveId(mixed $principal): string
    {
        if ($principal instanceof PrincipalInterface) {
            return $principal->id();
        }

        if (is_string($principal)) {
            return trim($principal);
        }

        if (is_int($principal)) {
            return (string) $principal;
        }

        if (is_object($principal) && method_exists($principal, 'getId')) {
            /** @var mixed $id */
            $id = $principal->getId();

            if (is_string($id) || is_int($id)) {
                return trim((string) $id);
            }
        }

        if (is_object($principal) && property_exists($principal, 'id')) {
            /** @var mixed $id */
            $id = $principal->id;

            if (is_string($id) || is_int($id)) {
                return trim((string) $id);
            }
        }

        if ($this->resolver !== null) {
            try {
                return $this->resolver->resolve($principal)->id();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }
}
