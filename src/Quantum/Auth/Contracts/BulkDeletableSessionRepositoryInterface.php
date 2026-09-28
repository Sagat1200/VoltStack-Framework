<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface BulkDeletableSessionRepositoryInterface extends AuthenticationSessionRepositoryInterface
{
    /**
     * @param array<int, string> $sessionIds
     */
    public function deleteByIds(array $sessionIds): int;

    /**
     * @param array<string, mixed> $criteria Same shape as FilterableAuthenticationSessionRepositoryInterface::findByCriteria
     *                                        (without limit/offset).
     */
    public function deleteByCriteria(array $criteria): int;
}
