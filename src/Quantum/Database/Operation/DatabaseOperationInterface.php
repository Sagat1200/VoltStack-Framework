<?php declare(strict_types=1);

namespace Quantum\Database\Operation;

/**
 * Marker interface para operaciones portátiles del subsistema.
 */
interface DatabaseOperationInterface
{
    public function kind(): OperationKind;
    public function describe(): string;
}
